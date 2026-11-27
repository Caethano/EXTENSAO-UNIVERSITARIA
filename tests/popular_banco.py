"""
Apaga TODAS as inscrições e repovoa o banco com inscrições de teste em cada
etapa do fluxo, pelos caminhos reais: participante pelo site, Comissão e
Defesa Civil pelo wp-admin. Os documentos, o histórico e a auditoria ficam
como numa inscrição de verdade.

Os documentos enviados (identidade, comprovante de residência, ficha
assinada, deferimento e recibo da tenda) são PDFs de teste com os dados de
cada inscrição, gerados pelo mesmo gerador de PDF do plugin. Uma cópia de
cada um fica em pdfs-teste/.

Antes de apagar, salva o banco e a pasta privada em backups/<data-hora>/.
As senhas dos participantes e da equipe de teste vão para cadastros-teste.md.

Uso, com a stack no ar:
    .\\tests\\popular.ps1 --apagar-tudo      (Windows)
    sh tests/popular.sh --apagar-tudo        (Linux/macOS)
"""

from __future__ import annotations

import argparse
import base64
import datetime
import io
import json
import shutil
import subprocess
import sys
import tarfile
import unicodedata

from festa import (
    BASE, FIXTURES, ROOT, Db, EquipeWP, Participante, _php_literal, arquivo, msg_do_redirect, wp_eval,
)

COMPLETO = ["finalizar", "ficha", "deferir", "tenda", "recibo", "aprovar", "colchoes"]
ANEXOS = ["identidade", "comprovante_residencia"]

# passos: o que acontece depois do pré-cadastro, em ordem.
# anexos: documentos opcionais enviados junto com a finalização.
CENARIOS = [
    dict(nome="Ana Beatriz Souza", grupo="Turma do Chimarrão",
         etapa="Rascunho: só fez o pré-cadastro", passos=[]),
    dict(nome="Bruno Henrique Lima", grupo="Amigos da Rua XV",
         etapa="Rascunho: só fez o pré-cadastro", passos=[]),
    dict(nome="Carla Mendes Rocha", grupo="Família Rocha",
         etapa="Aguardando análise: ficha gerada, falta enviar a assinada",
         passos=COMPLETO[:1], anexos=["identidade"], coccao="gas", espaco="1", qtd="8"),
    dict(nome="Diego Alves Pereira", grupo="Galera do Futsal",
         etapa="Aguardando análise: ficha assinada enviada",
         passos=COMPLETO[:2], coccao="carvao", espaco="2", qtd="20"),
    dict(nome="Eduarda Santos Costa", grupo="Grupo Raízes",
         etapa="Deferido: aguardando o sorteio da tenda",
         passos=COMPLETO[:3], coccao="gas", espaco="1", qtd="10"),
    dict(nome="Felipe Augusto Ribeiro", grupo="Vizinhos do Bairro Alto",
         etapa="Deferido: aguardando o sorteio da tenda",
         passos=COMPLETO[:3], coccao="carvao", espaco="1", qtd="15"),
    dict(nome="Gabriela Nunes Martins", grupo="Coral Vozes Unidas",
         etapa="Aguardando comprovantes: tenda sorteada, nada enviado",
         passos=COMPLETO[:4], coccao="gas", espaco="2", qtd="25"),
    dict(nome="Henrique Duarte Silva", grupo="Moto Clube Estrada",
         etapa="Aguardando comprovantes: recibo enviado, falta a Comissão aprovar",
         passos=COMPLETO[:5], coccao="carvao", espaco="1", qtd="12"),
    dict(nome="Isabela Carvalho Gomes", grupo="Amigas do Crochê",
         etapa="Aguardando comprovantes: recibo aprovado, faltam os colchões",
         passos=COMPLETO[:6], coccao="gas", espaco="1", qtd="9"),
    dict(nome="João Pedro Barbosa", grupo="Time do Domingo",
         etapa="Aguardando comprovantes: colchões doados, falta o recibo",
         passos=COMPLETO[:4] + ["colchoes"], coccao="carvao", espaco="2", qtd="18"),
    dict(nome="Larissa Moreira Dias", grupo="Turma da Faculdade",
         etapa="Concluído", passos=COMPLETO, coccao="gas", espaco="1", qtd="14"),
    dict(nome="Marcos Vinícius Araújo", grupo="Família Araújo",
         etapa="Concluído", passos=COMPLETO, coccao="carvao", espaco="2", qtd="22"),
    dict(nome="Natália Ferreira Lopes", grupo="Bloco da Alegria",
         etapa="Indeferido: a Comissão registrou a justificativa",
         passos=COMPLETO[:2] + ["indeferir"], coccao="gas", espaco="1", qtd="11"),
    # As duas últimas finalizam depois de o limite de vagas ser ajustado para
    # as vagas já ocupadas: entram na lista de espera, nesta ordem.
    dict(nome="Otávio Rezende Prado", grupo="Amigos do Vôlei",
         etapa="Lista de espera: 1º da fila", passos=["esgotar", "finalizar"],
         coccao="carvao", espaco="1", qtd="16"),
    dict(nome="Paula Teixeira Campos", grupo="Grupo Sol Nascente",
         etapa="Lista de espera: 2º da fila", passos=["esgotar", "finalizar", "ficha"],
         coccao="gas", espaco="2", qtd="13"),
]

JUSTIFICATIVA = ("Comprovante de residência em nome de outra pessoa e ficha de inscrição sem assinatura. "
                 "Documento de teste: justificativa fictícia.")

RUAS = ["Rua das Palmeiras", "Rua XV de Novembro", "Avenida Brasil", "Rua dos Ipês", "Rua Sete de Setembro",
        "Rua das Hortênsias", "Rua Santa Catarina", "Rua do Comércio", "Rua das Acácias", "Rua Bela Vista",
        "Rua dos Pinheiros", "Rua Esperança"]


def status_esperado(passos: list[str]) -> str:
    if {"aprovar", "colchoes"} <= set(passos):
        return "concluido"
    for passo, status in (("indeferir", "indeferido"), ("esgotar", "lista_espera"),
                          ("tenda", "aguardando_comprovantes"), ("deferir", "deferido"),
                          ("finalizar", "aguardando_analise")):
        if passo in passos:
            return status
    return "rascunho"


def wp_cli(script: str, *args: str) -> dict:
    proc = wp_eval(script, *args)
    if "@@FESTA_JSON@@" not in proc.stdout:
        sys.exit(f"WP-CLI falhou:\n{proc.stdout}\n{proc.stderr}")
    return json.loads(proc.stdout.split("@@FESTA_JSON@@", 1)[1].strip())


def php(modo: str) -> dict:
    return wp_cli((FIXTURES / "povoamento.php").read_text(encoding="utf-8"), modo)


def gerar_pdfs(pedidos: list[dict]) -> dict[str, bytes]:
    """Gera todos os PDFs numa só chamada ao WP-CLI."""
    corpo = (FIXTURES / "pdfs_teste.php").read_text(encoding="utf-8").split("<?php", 1)[1]
    script = "<?php\n$fp_in = json_decode(" + _php_literal(json.dumps(pedidos)) + ", true);\n" + corpo
    return {k: base64.b64decode(v) for k, v in wp_cli(script).items()}


def binario(*args: str) -> bytes:
    proc = subprocess.run(["docker", "compose", *args], cwd=ROOT, capture_output=True, timeout=300)
    if proc.returncode != 0:
        sys.exit(f"falhou: docker compose {' '.join(args)}\n{proc.stderr.decode(errors='replace')}")
    return proc.stdout


def fazer_backup(uploads_dir: str) -> str:
    destino = ROOT / "backups" / datetime.datetime.now().strftime("%Y%m%d-%H%M%S")
    destino.mkdir(parents=True)

    sql = binario("exec", "-T", "db", "sh", "-c",
                  "MYSQL_PWD=$MARIADB_PASSWORD mariadb-dump -u $MARIADB_USER $MARIADB_DATABASE")
    if b"CREATE TABLE" not in sql:
        sys.exit("o dump do banco veio vazio: nada foi apagado")
    (destino / "banco.sql").write_bytes(sql)

    pai, pasta = uploads_dir.rstrip("/").rsplit("/", 1)
    tgz = binario("exec", "-T", "wordpress", "tar", "czf", "-", "-C", pai, pasta)
    with tarfile.open(fileobj=io.BytesIO(tgz)) as tar:
        if not any(n.endswith("festa-chaves.php") for n in tar.getnames()):
            sys.exit("o backup da pasta privada não tem as chaves: nada foi apagado")
    (destino / "festa-privado.tgz").write_bytes(tgz)
    return str(destino.relative_to(ROOT))


def sem_acento(texto: str) -> str:
    return unicodedata.normalize("NFKD", texto).encode("ascii", "ignore").decode()


def email_de(nome: str) -> str:
    partes = sem_acento(nome).lower().split()
    return f"{partes[0]}.{partes[-1]}@example.test"


def slug(nome: str) -> str:
    partes = sem_acento(nome).lower().split()
    return f"{partes[0]}-{partes[-1]}"


def cpf_formatado(cpf: str) -> str:
    return f"{cpf[:3]}.{cpf[3:6]}.{cpf[6:9]}-{cpf[9:]}"


def main() -> None:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--apagar-tudo", action="store_true", help="confirma que TODAS as inscrições serão apagadas")
    if not ap.parse_args().apagar_tudo:
        ap.error("isto apaga TODAS as inscrições; rode de novo com --apagar-tudo")

    info = php("info")
    print(f"inscrições atuais: {len(info['inscricoes'])} {info['inscricoes']}")

    pasta_backup = fazer_backup(info["uploads_dir"])
    print(f"backup salvo em {pasta_backup}")

    limpo = php("limpar")
    print(f"apagadas: {limpo['removidas']}")
    if limpo["sobras_em_disco"]:
        print(f"AVISO: pastas que ficaram no disco: {limpo['sobras_em_disco']}")

    eq = limpo["equipe"]
    comissao = EquipeWP("comissao.teste", eq["comissao.teste"]["senha"], eq["comissao.teste"]["id"])
    defesa = EquipeWP("defesa.teste", eq["defesa.teste"]["senha"], eq["defesa.teste"]["id"])
    db = Db(info["prefix"])

    # 1. Pré-cadastros: o protocolo de cada um entra nos documentos.
    participantes = []
    tendas = iter(f"A-{n:02d}" for n in range(1, 100))
    for i, c in enumerate(CENARIOS):
        db.zerar_limites()  # o pré-cadastro aceita 10 por rede a cada 10 minutos
        p = Participante(info["pages"], db, nome=c["nome"])
        r = p.pre_cadastro()
        assert r.ok, f"{c['nome']}: pré-cadastro falhou: {r.corpo}"
        dados = {
            "id": p.id, "nome": p.nome, "cpf": p.cpf, "grupo": c["grupo"],
            "email": email_de(p.nome), "telefone": "(47) 99999-0000",
            "qtd": c.get("qtd", "10"), "coccao": c.get("coccao", "gas"), "espaco": c.get("espaco", "1"),
            "tenda": next(tendas) if "tenda" in c["passos"] else "",
            "rg": f"{4_100_000 + p.id * 37:,}".replace(",", ".") + f"-{p.id % 10}",
            "nascimento": f"{(p.id % 27) + 1:02d}/{(p.id % 12) + 1:02d}/{1975 + p.id % 25}",
            "endereco": f"{RUAS[i % len(RUAS)]}, {100 + p.id} — Centro — Cidade Exemplo/SC — CEP 89000-000",
            "uc": f"{30_000_000 + p.id * 911}",
        }
        participantes.append((p, c, dados))

    # 2. PDFs de teste de todas as inscrições.
    pedidos = []
    for p, c, dados in participantes:
        tipos = []
        if "finalizar" in c["passos"]:
            tipos += c.get("anexos", ANEXOS)
        tipos += [t for passo, t in (("ficha", "ficha_assinada"), ("deferir", "deferimento"),
                                     ("recibo", "recibo_tenda")) if passo in c["passos"]]
        pedidos += [{"chave": f"{p.id}:{t}", "tipo": t, "dados": dados} for t in tipos]
    pdfs = gerar_pdfs(pedidos)

    pasta_pdfs = ROOT / "pdfs-teste"
    shutil.rmtree(pasta_pdfs, ignore_errors=True)
    pasta_pdfs.mkdir()

    def doc(p: Participante, tipo: str):
        nome = f"{tipo.replace('_', '-')}-{slug(p.nome)}.pdf"
        conteudo = pdfs[f"{p.id}:{tipo}"]
        (pasta_pdfs / f"{p.id}-{nome}").write_bytes(conteudo)
        return arquivo(nome, conteudo, "application/pdf")

    # 3. Cada inscrição até a sua etapa. Gerar os PDFs leva alguns segundos:
    # as conexões abertas no passo 1 já foram fechadas pelo servidor (keep-alive)
    # e reaproveitá-las num POST derrubaria o envio. Os cookies ficam.
    for sessao in [p.http for p, _, _ in participantes] + [comissao.http, defesa.http]:
        sessao.close()
    limite = None
    for p, c, dados in participantes:
        nome = p.nome
        for passo in c["passos"]:
            if passo == "esgotar":
                if limite is None:
                    limite = php("esgotar")["limite"]
                    print(f"  limite de vagas ajustado para {limite} (vagas esgotadas): as próximas vão para a lista de espera")
            elif passo == "indeferir":
                assert msg_do_redirect(comissao.indeferir(p.id, JUSTIFICATIVA)) == "indeferido", \
                    f"{nome}: indeferimento falhou"
            elif passo == "finalizar":
                anexos = {t: doc(p, t) for t in c.get("anexos", ANEXOS)}
                r = p.finalizar(arquivos=anexos, grupo=dados["grupo"], email=dados["email"],
                                telefone=dados["telefone"], qtd_participantes=dados["qtd"],
                                coccao=dados["coccao"], espaco=dados["espaco"])
                assert r.ok, f"{nome}: finalização falhou: {r.corpo}"
            elif passo == "ficha":
                r = p.enviar_documento("ficha_assinada", doc(p, "ficha_assinada"))
                assert r.ok, f"{nome}: envio da ficha falhou: {r.corpo}"
            elif passo == "deferir":
                assert msg_do_redirect(comissao.deferir(p.id, doc(p, "deferimento"))) == "deferido", \
                    f"{nome}: deferimento falhou"
            elif passo == "tenda":
                assert msg_do_redirect(comissao.tenda(p.id, dados["tenda"])) == "tenda", \
                    f"{nome}: sorteio da tenda falhou"
            elif passo == "recibo":
                r = p.enviar_documento("recibo_tenda", doc(p, "recibo_tenda"))
                assert r.ok, f"{nome}: envio do recibo falhou: {r.corpo}"
            elif passo == "aprovar":
                assert msg_do_redirect(comissao.aprovar_recibo(p.id)) == "recibo", \
                    f"{nome}: aprovação do recibo falhou"
            elif passo == "colchoes":
                defesa.confirmar_colchoes(p.cpf)
                assert db.meta(p.id, "_colchoes_confirmados") == "1", f"{nome}: confirmação dos colchões falhou"

        esperado = status_esperado(c["passos"])
        obtido = db.status(p.id)
        assert obtido == esperado, f"{nome}: status {obtido}, esperado {esperado}"
        print(f"  #{p.id:<4} {obtido:<24} {nome}")

    linhas = [
        "# Cadastros de teste",
        "",
        f"Gerado por `tests/popular_banco.py` em {datetime.datetime.now():%d/%m/%Y %H:%M}.",
        "Dados fictícios, só para este ambiente local. Backup do que existia antes: "
        f"`{pasta_backup}`. Cópias dos PDFs enviados: `pdfs-teste/`.",
        "",
        "## Participantes",
        "",
        f"Login em {info['pages']['painel']} com CPF e senha.",
        "",
        "| Protocolo | Responsável | Grupo | CPF | Senha | Tenda | Etapa |",
        "|---|---|---|---|---|---|---|",
    ]
    for p, c, dados in participantes:
        linhas.append(f"| #{p.id} | {p.nome} | {c['grupo']} | {cpf_formatado(p.cpf)} | `{p.senha}` "
                      f"| {dados['tenda'] or '—'} | {c['etapa']} |")
    linhas += [
        "",
        "## Equipe (wp-admin)",
        "",
        f"Login em {BASE}/wp-login.php",
        "",
        "| Usuário | Senha | Papel |",
        "|---|---|---|",
        f"| comissao.teste | `{eq['comissao.teste']['senha']}` | Comissão |",
        f"| defesa.teste | `{eq['defesa.teste']['senha']}` | Defesa Civil |",
        "",
        "As senhas da equipe são renovadas a cada execução do script.",
        "",
        "## Limite de vagas",
        "",
        (f"Para formar a lista de espera, o limite de vagas foi ajustado para **{limite}** (as vagas já ocupadas). "
         "Mude em Festa dos Amigos → Configurações; 0 = sem limite.") if limite is not None
        else "O limite de vagas não foi alterado.",
        "",
    ]
    (ROOT / "cadastros-teste.md").write_text("\n".join(linhas), encoding="utf-8")
    print(f"\n{len(participantes)} inscrições criadas, {len(pdfs)} PDFs enviados (cópias em pdfs-teste/); "
          "logins e senhas em cadastros-teste.md")


if __name__ == "__main__":
    main()
