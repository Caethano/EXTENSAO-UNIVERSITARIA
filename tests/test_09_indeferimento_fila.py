"""
Indeferimento, lista de espera, limite de vagas e dashboard.

- Indeferir (com justificativa obrigatória) e reabrir (com motivo), pela Comissão.
- Limite de vagas configurável: 0 = sem limite; sem vaga, ninguém é deferido,
  nem com dois deferimentos ao mesmo tempo.
- Lista de espera: quem finaliza com as vagas esgotadas entra na fila, na
  ordem de `_fila_em`; "Chamar próximo da fila" pega o primeiro.
- Dashboard: só contagens, iguais às do banco, sem dado pessoal nenhum.

Os testes que mexem no limite usam o fixture `limite`, que o devolve a 0
(sem limite) no fim: o resto da suíte roda sempre sem limite.
"""

import re
import subprocess
import threading
import time
from urllib.parse import urljoin

import pytest
import requests

from festa import (
    ADMIN, ADMIN_POST, ROOT, EquipeWP, Mailpit, Participante, achar_form, campos_do_form, msg_do_redirect, pdf,
    soup, wp_eval,
)

JUSTIFICATIVA = "Documento de identidade ilegível e ficha de inscrição sem assinatura."
MOTIVO = "Documentação regularizada pelo participante na secretaria."


# -- fixtures ----------------------------------------------------------------

@pytest.fixture(scope="module")
def mailpit() -> Mailpit:
    return Mailpit()


class Limite:
    """Define o limite de vagas pela tela de Configurações, como o administrador faria."""

    def __init__(self, admin, db, nova_ocupada):
        # nova_ocupada(): cria uma inscrição deferida (ocupa uma vaga).
        self.admin, self.db, self.nova_ocupada = admin, db, nova_ocupada

    def definir(self, n: int) -> int:
        r = self.admin.salvar_config(limite_vagas=str(n))
        assert r.status_code == 302 and "saved=1" in r.headers["Location"], r.headers.get("Location")
        return n

    def folga(self, k: int) -> int:
        """Limite = vagas já ocupadas + k (sobram k vagas)."""
        return self.definir(self.db.ocupadas() + k)

    def esgotar(self) -> int:
        """Limite = vagas já ocupadas (nenhuma sobra). Precisa de ao menos uma ocupada."""
        if self.db.ocupadas() == 0:
            self.nova_ocupada()
        return self.definir(self.db.ocupadas())


@pytest.fixture
def limite(admin, db, fluxo):
    lim = Limite(admin, db, lambda: fluxo("deferido"))
    yield lim
    lim.definir(0)


def _status_html(html: str) -> str:
    badge = soup(html).select_one(".festa-badge")
    return badge.get_text(strip=True) if badge else ""


def _posicao_no_painel(p) -> str:
    el = soup(p.painel()).select_one(".festa-fila-destaque span")
    return el.get_text(strip=True) if el else ""


def _cartoes(html: str) -> dict[str, str]:
    """Números dos cartões do dashboard: data-dash → texto do número."""
    out = {}
    for card in soup(html).select("[data-dash]"):
        num = card.select_one(".festa-dash-num")
        out[card["data-dash"]] = num.get_text(strip=True) if num else ""
    return out


def _apagar_inscricoes(*ids: int) -> None:
    """Remove inscrições que a limpeza da suíte não acha mais pelo nome (anonimizadas)."""
    lista = ",".join(str(int(i)) for i in ids)
    wp_eval(
        "<?php\nglobal $wpdb;\n"
        f"foreach ([{lista}] as $id) {{ Festa\\Cpt\\CptInscricao::delete($id); "
        "$wpdb->delete($wpdb->prefix . 'festa_audit_log', ['inscricao_id' => $id]); }\n"
    )


# -- indeferimento -----------------------------------------------------------

@pytest.mark.parametrize("texto", ["", "   ", "curta", "<b></b><i></i>"])
def test_indeferir_sem_justificativa_valida_e_recusado(fluxo, comissao, db, texto):
    p = fluxo("aguardando_analise")
    antes = db.retrato(p.id)
    r = comissao.indeferir(p.id, texto)
    assert msg_do_redirect(r) == "justificativa_curta"
    assert db.retrato(p.id) == antes
    assert len(db.transicoes(p.id)) == 1  # só a finalização


def test_indeferir_com_justificativa_registra_tudo(fluxo, comissao, db, amb):
    p = fluxo("aguardando_analise")
    r = comissao.indeferir(p.id, JUSTIFICATIVA)
    assert msg_do_redirect(r) == "indeferido"

    assert db.status(p.id) == "indeferido"
    assert db.meta(p.id, "_indeferimento_motivo") == JUSTIFICATIVA
    assert db.meta(p.id, "_indeferido_em")
    assert db.transicoes(p.id)[-1] == ("aguardando_analise", "indeferido", f"Justificativa: {JUSTIFICATIVA}")
    quem = db.scalar(
        f"SELECT user_id FROM {db.p}festa_audit_log WHERE inscricao_id={p.id} AND new_value='indeferido'"
    )
    assert quem == str(amb["wp"]["comissao"]["id"]), "a auditoria deveria registrar quem indeferiu"

    tela = comissao.tela_inscricao(p.id)
    assert "Inscrição indeferida" in tela and JUSTIFICATIVA in tela
    assert achar_form(tela, "festa_reabrir") is not None
    assert achar_form(tela, "festa_deferir") is None


def test_reabrir_exige_motivo_e_volta_para_analise(fluxo, comissao, db):
    p = fluxo("aguardando_analise")
    assert msg_do_redirect(comissao.indeferir(p.id, JUSTIFICATIVA)) == "indeferido"

    assert msg_do_redirect(comissao.reabrir(p.id, "")) == "motivo_curto"
    assert db.status(p.id) == "indeferido"

    assert msg_do_redirect(comissao.reabrir(p.id, MOTIVO)) == "reaberto"
    assert db.status(p.id) == "aguardando_analise"
    assert db.meta(p.id, "_reabertura_motivo") == MOTIVO
    assert db.meta(p.id, "_indeferimento_motivo") is None, "a justificativa antiga não vale mais depois de reabrir"
    assert db.transicoes(p.id)[-1] == ("indeferido", "aguardando_analise", f"Motivo da reabertura: {MOTIVO}")

    # Reaberta, segue o fluxo normal.
    assert msg_do_redirect(comissao.deferir(p.id)) == "deferido"


def test_participante_indeferido_nao_envia_nada(fluxo, comissao, db):
    p = fluxo("aguardando_analise")
    assert msg_do_redirect(comissao.indeferir(p.id, JUSTIFICATIVA)) == "indeferido"
    antes = db.retrato(p.id)

    r = p.enviar_documento("ficha_assinada")
    assert r.status == 409 and "indeferida" in r.mensagem
    assert p.enviar_documento("recibo_tenda").status == 409
    assert p.finalizar().status == 409
    assert db.retrato(p.id) == antes

    painel = p.painel()
    assert "Inscrição indeferida" in painel and JUSTIFICATIVA in painel
    assert _status_html(painel) == "Indeferido"
    assert achar_form(painel, "festa_upload_documentos") is None, "o painel ofereceu envio numa inscrição indeferida"
    assert "Indeferida" in soup(painel).select_one(".festa-stepper").get_text()


def test_justificativa_e_texto_puro_sem_html(fluxo, comissao, db, mailpit):
    """Justificativa é entrada da Comissão: tags saem na gravação e o resto é escapado na saída."""
    p = fluxo("aguardando_analise")
    ataque = ('<script>alert("xss")</script>Documento <b>ilegível</b> & ficha "sem" assinatura'
              '<img src=x onerror=alert(1)>')
    assert msg_do_redirect(comissao.indeferir(p.id, ataque)) == "indeferido"

    guardado = db.meta(p.id, "_indeferimento_motivo") or ""
    assert "<" not in guardado and "alert" not in guardado

    seguro = "Documento ilegível &amp; ficha &quot;sem&quot; assinatura"
    for html in (p.painel(), comissao.tela_inscricao(p.id), comissao.pagina("festa_audit_log").text):
        assert 'alert("xss")' not in html and "onerror" not in html and "<b>ilegível</b>" not in html
        assert seguro in html

    corpo = mailpit.mensagens_para(db.meta(p.id, "_email"), "Indeferida")[0]["HTML"]
    assert "onerror" not in corpo and "<b>ilegível</b>" not in corpo
    assert seguro in corpo


def test_emails_de_indeferimento_e_reabertura(fluxo, comissao, db, mailpit):
    p = fluxo("aguardando_analise")
    email = db.meta(p.id, "_email")
    assert msg_do_redirect(comissao.indeferir(p.id, JUSTIFICATIVA)) == "indeferido"
    assert msg_do_redirect(comissao.reabrir(p.id, MOTIVO)) == "reaberto"

    indeferida = mailpit.mensagens_para(email, "Inscrição Indeferida")
    assert len(indeferida) == 1 and JUSTIFICATIVA in indeferida[0]["HTML"]
    assert f"#{p.id}" in indeferida[0]["Subject"]

    reaberta = mailpit.mensagens_para(email, "Reaberta para Análise")
    assert len(reaberta) == 1 and MOTIVO in reaberta[0]["HTML"]


def test_indeferir_e_reabrir_so_nas_fases_certas(fluxo, comissao, db):
    """Deferida não volta a ser indeferida; em análise não há o que reabrir."""
    deferida, em_analise = fluxo("deferido"), fluxo("aguardando_analise")
    nonces = comissao.nonces([f"festa_indeferir_{deferida.id}", f"festa_reabrir_{em_analise.id}"])

    r = comissao.admin_post("festa_indeferir", {
        "id": deferida.id, "_wpnonce": nonces[f"festa_indeferir_{deferida.id}"], "justificativa": JUSTIFICATIVA,
    })
    assert msg_do_redirect(r) == "status_erro" and db.status(deferida.id) == "deferido"

    r = comissao.admin_post("festa_reabrir", {
        "id": em_analise.id, "_wpnonce": nonces[f"festa_reabrir_{em_analise.id}"], "motivo": MOTIVO,
    })
    assert msg_do_redirect(r) == "status_erro" and db.status(em_analise.id) == "aguardando_analise"


def test_comissao_do_portal_indefere_e_fica_registrada(fluxo, portal, db):
    p = fluxo("aguardando_analise")
    conta = portal("comissao")
    assert msg_do_redirect(conta.indeferir(p.id, JUSTIFICATIVA)) == "indeferido"
    assert db.status(p.id) == "indeferido"
    ator = db.scalar(
        f"SELECT actor FROM {db.p}festa_audit_log WHERE inscricao_id={p.id} AND new_value='indeferido'"
    )
    assert ator and ator.endswith("(portal)")


def test_participante_nao_ve_status_nem_justificativa_de_outro(fluxo, comissao):
    a, b = fluxo("aguardando_analise"), fluxo("aguardando_analise")
    segredo = f"Motivo exclusivo da inscrição {a.id}: comprovante divergente."
    assert msg_do_redirect(comissao.indeferir(a.id, segredo)) == "indeferido"

    painel_b = b.painel()
    assert segredo not in painel_b and f"#{a.id}" not in painel_b
    assert _status_html(painel_b) == "Aguardando Análise"
    assert segredo in a.painel()


def test_csv_traz_a_justificativa(fluxo, comissao):
    p = fluxo("aguardando_analise")
    assert msg_do_redirect(comissao.indeferir(p.id, JUSTIFICATIVA)) == "indeferido"
    html = comissao.http.get(comissao.url_lista(status="indeferido")).text
    href = next(a["href"] for a in soup(html).find_all("a", href=True) if "action=festa_export&" in a["href"])
    csv = comissao.http.get(href).content.decode("utf-8-sig")
    assert "Justificativa do indeferimento" in csv and JUSTIFICATIVA in csv
    assert p.cpf not in csv


# -- limite de vagas -----------------------------------------------------------

def test_limite_zero_e_sem_limite(limite, novo_participante, comissao, admin, db):
    limite.definir(0)
    p = novo_participante().inscrito()
    assert db.status(p.id) == "aguardando_analise", "sem limite, ninguém vai para a lista de espera"
    assert msg_do_redirect(comissao.deferir(p.id)) == "deferido"

    cartoes = _cartoes(admin.pagina("festa-dashboard").text)
    assert cartoes["vagas"] == "—"
    assert "Sem limite" in admin.pagina("festa-dashboard").text


def test_vagas_restantes_calculadas(limite, fluxo, comissao, admin, db):
    em_analise = fluxo("aguardando_analise")
    n = limite.folga(2)
    assert _cartoes(admin.pagina("festa-dashboard").text)["vagas"] == "2"
    assert f"Vagas restantes: 2 de {n}." in admin.pagina("festa-configuracoes").text

    assert msg_do_redirect(comissao.deferir(em_analise.id)) == "deferido"
    assert db.ocupadas() == n - 1
    assert _cartoes(admin.pagina("festa-dashboard").text)["vagas"] == "1"


def test_vagas_restantes_nunca_negativas(limite, fluxo, admin, db):
    while db.ocupadas() < 2:
        fluxo("deferido")
    limite.definir(1)  # abaixo do que já está ocupado
    assert _cartoes(admin.pagina("festa-dashboard").text)["vagas"] == "0"
    assert "acima do limite" in admin.pagina("festa-configuracoes").text


def test_deferir_bloqueado_com_vagas_esgotadas(limite, fluxo, comissao, db):
    p = fluxo("aguardando_analise")
    limite.esgotar()

    tela = comissao.tela_inscricao(p.id)
    assert "Não há vagas restantes" in tela
    assert achar_form(tela, "festa_deferir") is None, "a tela ofereceu deferir sem vaga"

    antes = db.retrato(p.id)
    nonce = comissao.nonces([f"festa_deferir_{p.id}"])[f"festa_deferir_{p.id}"]
    r = comissao.http.post(ADMIN_POST, data={"action": "festa_deferir", "id": p.id, "_wpnonce": nonce},
                           files={"documento": pdf("deferimento.pdf")}, allow_redirects=False)
    assert msg_do_redirect(r) == "vagas_esgotadas"
    assert db.retrato(p.id) == antes
    assert "não há vagas restantes" in comissao.http.get(r.headers["Location"]).text


@pytest.mark.lento
@pytest.mark.parametrize("vagas,disputas", [(1, 4), (2, 5)])
def test_deferimentos_simultaneos_nao_passam_do_limite(limite, novo_participante, amb, db, vagas, disputas):
    alvos = [novo_participante().inscrito() for _ in range(disputas)]
    n = limite.folga(vagas)

    # Cada membro da Comissão com a sua sessão e o formulário já aberto na tela.
    u = amb["wp"]["comissao"]
    envios = []
    for p in alvos:
        eq = EquipeWP(u["login"], u["senha"], u["id"])
        url = eq.url_inscricao(p.id)
        form = achar_form(eq.http.get(url).text, "festa_deferir")
        assert form is not None
        envios.append((eq, urljoin(url, form.get("action")), campos_do_form(form)))

    largada = threading.Barrier(len(envios))
    msgs = [""] * len(envios)

    def deferir(i: int) -> None:
        eq, destino, dados = envios[i]
        largada.wait()
        r = eq.http.post(destino, data=dados, files={"documento": pdf("deferimento.pdf")}, allow_redirects=False)
        msgs[i] = msg_do_redirect(r)

    threads = [threading.Thread(target=deferir, args=(i,)) for i in range(len(envios))]
    for t in threads:
        t.start()
    for t in threads:
        t.join()

    deferidos = [p.id for p in alvos if db.status(p.id) == "deferido"]
    assert len(deferidos) == vagas, f"{len(deferidos)} deferidos para {vagas} vaga(s): {msgs}"
    assert db.ocupadas() == n, "as vagas ocupadas passaram do limite"
    assert msgs.count("deferido") == vagas
    assert set(msgs) <= {"deferido", "vagas_esgotadas", "vagas_ocupado"}, msgs
    for p in alvos:
        if p.id not in deferidos:
            assert db.status(p.id) == "aguardando_analise"
            assert db.meta(p.id, "_upload_deferimento") is None, "o documento de quem não levou a vaga ficou gravado"


@pytest.mark.lento
def test_trava_do_banco_vale_para_a_ficha_e_some_com_a_conexao(fluxo, db):
    """
    Outra conexão segura a trava das vagas: o envio da ficha espera e desiste
    (nada é gravado). Quando aquela conexão fecha, o banco solta a trava
    sozinho — não existe trava abandonada.
    """
    p = fluxo("aguardando_analise")
    nome = f"CONCAT('festa_', MD5(CONCAT(DATABASE(), '|', '{db.p}', '|', 'festa_vagas')))"
    segura = subprocess.Popen(
        ["docker", "compose", "exec", "-T", "db", "sh", "-c",
         'exec mariadb -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE" -N -B'],
        cwd=ROOT, stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True,
    )
    segura.stdin.write(f"SELECT GET_LOCK({nome}, 0); SELECT SLEEP(19);\n")
    segura.stdin.close()
    try:
        time.sleep(2)
        r = p.enviar_documento("ficha_assinada")
        assert r.status == 503, r.corpo
        assert db.meta(p.id, "_upload_ficha_assinada") is None
    finally:
        segura.wait(timeout=60)
    assert p.enviar_documento("ficha_assinada").ok, "a trava deveria sumir com a conexão que a segurava"


@pytest.mark.parametrize("valor", ["-1", "abc", "2.5", "10 vagas", "1e3"])
def test_configuracao_recusa_limite_invalido(admin, db, fluxo, valor):
    alvo = fluxo("rascunho")
    antes = db.retrato(alvo.id)
    r = admin.salvar_config(limite_vagas=valor)
    assert r.status_code == 302 and "erro=limite" in r.headers["Location"], r.headers.get("Location")
    assert db.retrato(alvo.id) == antes, "um valor inválido mudou as configurações"
    assert "o limite de vagas precisa ser um número inteiro" in admin.http.get(r.headers["Location"]).text


def test_mudanca_do_limite_fica_na_auditoria(limite, db):
    limite.definir(7)
    detalhe = db.scalar(
        f"SELECT detail FROM {db.p}festa_audit_log WHERE action='configuracoes' ORDER BY id DESC LIMIT 1"
    )
    assert detalhe and "Limite de vagas" in detalhe and "→ 7" in detalhe


# -- lista de espera -----------------------------------------------------------

def test_finalizacao_com_vagas_esgotadas_entra_na_fila(limite, novo_participante, db, mailpit, amb):
    limite.esgotar()
    assert "Vagas esgotadas" in requests.get(amb["pages"]["pre"]).text, "o pré-cadastro deveria avisar"

    p = novo_participante()
    assert p.pre_cadastro().ok, "o pré-cadastro continua aberto com as vagas esgotadas"
    r = p.finalizar()
    assert r.ok and r.dados.get("status") == "lista_espera" and r.dados.get("posicao", 0) >= 1
    assert db.status(p.id) == "lista_espera"
    assert re.fullmatch(r"\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}", db.meta(p.id, "_fila_em") or "")
    assert db.transicoes(p.id)[-1][:2] == ("rascunho", "lista_espera")

    painel = p.painel()
    assert "Você está na lista de espera" in painel and _status_html(painel) == "Lista de Espera"
    assert _posicao_no_painel(p).endswith("º")
    assert achar_form(painel, "festa_upload_documentos") is not None, "na fila o participante já pode mandar a ficha"
    assert mailpit.mensagens_para(db.meta(p.id, "_email"), "Lista de Espera")


def test_chamar_proximo_pega_o_primeiro_da_fila(limite, novo_participante, comissao, db, mailpit):
    limite.esgotar()
    a, b, c = (novo_participante().inscrito() for _ in range(3))
    assert {db.status(x.id) for x in (a, b, c)} == {"lista_espera"}

    # Ordem de chegada b, c, a — diferente da ordem dos protocolos.
    db.empurrar_fila_para_o_fim()
    db.set_meta(b.id, "_fila_em", "2000-01-01 08:00:00")
    db.set_meta(c.id, "_fila_em", "2000-01-01 08:00:05")
    db.set_meta(a.id, "_fila_em", "2000-01-01 08:00:10")
    assert [_posicao_no_painel(x) for x in (b, c, a)] == ["1º", "2º", "3º"]

    # Sem vaga, o botão não aparece.
    lista = comissao.http.get(comissao.url_lista(status="lista_espera")).text
    assert achar_form(lista, "festa_chamar_fila") is None

    limite.folga(1)
    r = comissao.chamar_fila()
    assert msg_do_redirect(r) == "chamado" and f"chamado={b.id}" in r.headers["Location"]
    assert db.status(b.id) == "aguardando_analise"
    assert db.status(a.id) == db.status(c.id) == "lista_espera"
    assert db.transicoes(b.id)[-1] == ("lista_espera", "aguardando_analise", "Chamada da lista de espera.")
    assert mailpit.mensagens_para(db.meta(b.id, "_email"), "chamado da lista de espera")
    assert [_posicao_no_painel(x) for x in (c, a)] == ["1º", "2º"]

    r = comissao.chamar_fila()
    assert f"chamado={c.id}" in r.headers["Location"]
    assert db.status(c.id) == "aguardando_analise" and db.status(a.id) == "lista_espera"


def test_chamar_da_fila_bloqueado_sem_vaga(limite, novo_participante, comissao, db):
    limite.esgotar()
    p = novo_participante().inscrito()
    assert db.status(p.id) == "lista_espera"

    lista = comissao.http.get(comissao.url_lista(status="lista_espera")).text
    assert "Sem vaga restante" in lista and achar_form(lista, "festa_chamar_fila") is None

    fila_antes = db.query(f"SELECT post_id FROM {db.p}postmeta WHERE meta_key='_status' AND meta_value='lista_espera'")
    nonce = comissao.nonces(["festa_chamar_fila"])["festa_chamar_fila"]
    r = comissao.admin_post("festa_chamar_fila", {"_wpnonce": nonce})
    assert msg_do_redirect(r) == "fila_sem_vaga"
    assert db.query(f"SELECT post_id FROM {db.p}postmeta WHERE meta_key='_status' AND meta_value='lista_espera'") == fila_antes


def test_indeferir_quem_esta_na_fila(limite, novo_participante, comissao, db):
    limite.esgotar()
    p = novo_participante().inscrito()
    assert db.status(p.id) == "lista_espera"
    assert msg_do_redirect(comissao.indeferir(p.id, JUSTIFICATIVA)) == "indeferido"
    assert db.status(p.id) == "indeferido"
    assert db.transicoes(p.id)[-1][:2] == ("lista_espera", "indeferido")


# -- permissões ----------------------------------------------------------------

ACOES_NOVAS = {
    "festa_indeferir":     ("festa_indeferir_{id}", {"justificativa": JUSTIFICATIVA}),
    "festa_reabrir":       ("festa_reabrir_{id}", {"motivo": MOTIVO}),
    "festa_chamar_fila":   ("festa_chamar_fila", {}),
    "festa_save_settings": ("festa_settings", {"aberto": "1", "limite_vagas": "1"}),
}


@pytest.fixture(scope="module")
def alvos(amb, db, comissao, admin):
    """Uma inscrição em análise, uma indeferida e uma na fila (sem limite: chamar seria possível)."""
    db.zerar_limites()

    def inscrito() -> Participante:
        return Participante(amb["pages"], db).inscrito()

    def ocupada() -> None:
        assert msg_do_redirect(comissao.deferir(inscrito().id)) == "deferido"

    analise, indeferida = inscrito(), inscrito()
    assert msg_do_redirect(comissao.indeferir(indeferida.id, JUSTIFICATIVA)) == "indeferido"
    lim = Limite(admin, db, ocupada)
    lim.esgotar()
    try:
        na_fila = inscrito()
    finally:
        lim.definir(0)
    assert db.status(na_fila.id) == "lista_espera"
    return {"festa_indeferir": analise, "festa_reabrir": indeferida,
            "festa_chamar_fila": na_fila, "festa_save_settings": analise}


@pytest.fixture(scope="module")
def nonces_novas(alvos, defesa, assinante):
    """Nonces válidos das sessões da Defesa Civil e do assinante: a recusa tem de vir da permissão."""
    nomes = sorted({ACOES_NOVAS[a][0].format(id=alvos[a].id) for a in ACOES_NOVAS})
    return {"defesa": defesa.nonces(nomes), "assinante": assinante.nonces(nomes)}


def _fila(db) -> list[list[str]]:
    return db.query(f"SELECT post_id FROM {db.p}postmeta WHERE meta_key='_status' AND meta_value='lista_espera' ORDER BY post_id")


@pytest.mark.parametrize("papel", ["defesa", "assinante"])
@pytest.mark.parametrize("acao", list(ACOES_NOVAS))
def test_wp_admin_sem_permissao_nao_executa_as_acoes_novas(request, papel, acao, alvos, nonces_novas, db):
    usuario = request.getfixturevalue(papel)
    alvo = alvos[acao]
    nome_nonce, extra = ACOES_NOVAS[acao]
    nonce = nonces_novas[papel][nome_nonce.format(id=alvo.id)]
    antes, fila = db.retrato(alvo.id), _fila(db)

    r = usuario.admin_post(acao, {"id": alvo.id, "_wpnonce": nonce, **extra})

    assert r.status_code == 403, f"{papel} executou {acao}: HTTP {r.status_code} {r.headers.get('Location', '')}"
    assert db.retrato(alvo.id) == antes and _fila(db) == fila


@pytest.mark.parametrize("acao", list(ACOES_NOVAS))
def test_participante_e_visitante_nao_executam_as_acoes_novas(acao, alvos, db):
    alvo = alvos[acao]
    antes, fila = db.retrato(alvo.id), _fila(db)
    dados = {"action": acao, "id": alvo.id, **ACOES_NOVAS[acao][1]}

    # O participante, com a sessão dele aberta, e um visitante qualquer.
    for http in (alvo.http, requests.Session()):
        r = http.post(ADMIN_POST, data=dados, allow_redirects=False)
        assert r.status_code in (400, 403)
    assert db.retrato(alvo.id) == antes and _fila(db) == fila


@pytest.mark.parametrize("papel", ["leitor", "defesa", "painel"])
@pytest.mark.parametrize("acao", list(ACOES_NOVAS))
def test_portal_sem_permissao_nao_executa_as_acoes_novas(portal, papel, acao, alvos, db):
    conta = portal(papel)
    alvo = alvos[acao]
    antes, fila = db.retrato(alvo.id), _fila(db)
    r = conta.post(acao, {"id": alvo.id, **ACOES_NOVAS[acao][1]})
    assert r.status_code == 403
    assert db.retrato(alvo.id) == antes and _fila(db) == fila


def test_acoes_novas_exigem_post_e_nonce(fluxo, comissao, db):
    p = fluxo("aguardando_analise")
    antes = db.retrato(p.id)
    r = comissao.admin_post("festa_indeferir", {"id": p.id, "justificativa": JUSTIFICATIVA})
    assert r.status_code == 403, "sem nonce"
    nonce = comissao.nonces([f"festa_indeferir_{p.id}"])[f"festa_indeferir_{p.id}"]
    r = comissao.admin_post("festa_indeferir", {"id": p.id, "_wpnonce": nonce, "justificativa": JUSTIFICATIVA}, metodo="get")
    assert r.status_code == 405, "ação de escrita por GET"
    assert db.retrato(p.id) == antes


def test_limite_de_indeferir_e_reabrir_a_mesma_inscricao(fluxo, comissao, db):
    """Alternar indeferir/reabrir mandaria um e-mail por vez: cada inscrição tem um teto diário."""
    p = fluxo("aguardando_analise")
    chave = f"acoes_inscricao:{p.id}"
    db.query(
        f"INSERT INTO {db.p}festa_counters (k, n, expires) VALUES ('{chave}', 6, UNIX_TIMESTAMP() + 86400) "
        "ON DUPLICATE KEY UPDATE n = 6, expires = UNIX_TIMESTAMP() + 86400"
    )
    try:
        assert msg_do_redirect(comissao.indeferir(p.id, JUSTIFICATIVA)) == "rate_limit"
        assert db.status(p.id) == "aguardando_analise"
    finally:
        db.query(f"DELETE FROM {db.p}festa_counters WHERE k = '{chave}'")


def test_justificativa_longa_cabe_inteira_e_quebra_de_linha_conta_um(fluxo, comissao, db):
    p = fluxo("aguardando_analise")
    assert msg_do_redirect(comissao.indeferir(p.id, "x" * 901)) == "justificativa_curta", "passou do máximo"

    # 900 caracteres contando a quebra de linha como um só (como o maxlength do navegador).
    longa = "a" * 450 + "\r\n" + "b" * 449
    assert msg_do_redirect(comissao.indeferir(p.id, longa)) == "indeferido"
    # Comparação feita no banco: a saída do cliente MariaDB escapa as quebras de linha.
    esperado = "CONCAT(REPEAT('a', 450), CHAR(10), REPEAT('b', 449))"
    assert db.scalar(
        f"SELECT meta_value = {esperado} FROM {db.p}postmeta WHERE post_id={p.id} AND meta_key='_indeferimento_motivo'"
    ) == "1"
    assert db.scalar(
        f"SELECT detail = CONCAT('Justificativa: ', {esperado}) FROM {db.p}festa_audit_log "
        f"WHERE inscricao_id={p.id} AND new_value='indeferido' ORDER BY id DESC LIMIT 1"
    ) == "1", "a auditoria cortou a justificativa"


def test_limite_de_acoes_seguidas(fluxo, comissao, db):
    """Indeferir/reabrir/chamar avisam o participante: um usuário não dispara isso sem limite."""
    p = fluxo("aguardando_analise")
    chave = db.scalar(f"SELECT CONCAT('acoes_status:', MD5('wp{comissao.user_id}'))")
    db.query(
        f"INSERT INTO {db.p}festa_counters (k, n, expires) VALUES ('{chave}', 999, UNIX_TIMESTAMP() + 600) "
        "ON DUPLICATE KEY UPDATE n = 999, expires = UNIX_TIMESTAMP() + 600"
    )
    try:
        assert msg_do_redirect(comissao.indeferir(p.id, JUSTIFICATIVA)) == "rate_limit"
        assert db.status(p.id) == "aguardando_analise"
    finally:
        db.query(f"DELETE FROM {db.p}festa_counters WHERE k = '{chave}'")
    assert msg_do_redirect(comissao.indeferir(p.id, JUSTIFICATIVA)) == "indeferido"


# -- dashboard -----------------------------------------------------------------

TELA_DASHBOARD = {"admin": 200, "comissao": 200, "defesa": 403, "assinante": 403}


@pytest.mark.parametrize("papel,codigo", list(TELA_DASHBOARD.items()))
def test_acesso_ao_dashboard_no_wp_admin(request, papel, codigo):
    assert request.getfixturevalue(papel).pagina("festa-dashboard").status_code == codigo


@pytest.mark.parametrize("papel,codigo", [("painel", 200), ("comissao", 403), ("leitor", 403), ("defesa", 403)])
def test_acesso_ao_dashboard_no_portal(portal, papel, codigo):
    r = portal(papel).tela("dashboard")
    assert r.status_code == codigo
    if codigo == 200:
        assert _cartoes(r.text)["total"].isdigit()


def test_conta_so_do_dashboard_nao_ve_inscricoes(portal):
    conta = portal("painel")
    assert conta.tela("inscricoes").status_code == 403
    assert conta.tela("auditoria").status_code == 403


def test_participante_nao_ve_o_dashboard(fluxo, amb):
    p = fluxo("aguardando_analise")
    r = p.http.get(f"{ADMIN}admin.php?page=festa-dashboard", allow_redirects=False)
    assert r.status_code == 302 and "wp-login.php" in r.headers["Location"]
    r = p.http.get(amb["portal_url"], params={"tela": "dashboard"})
    assert not soup(r.text).select("[data-dash]")


def test_dashboard_bate_com_o_banco(fluxo, comissao, admin, db, portal):
    p = fluxo("aguardando_analise")
    assert msg_do_redirect(comissao.indeferir(p.id, JUSTIFICATIVA)) == "indeferido"

    banco = db.contagem_por_status()
    for html in (admin.pagina("festa-dashboard").text, portal("painel").tela("dashboard").text):
        cartoes = _cartoes(html)
        assert int(cartoes["total"]) == sum(banco.values())
        for status in ("rascunho", "aguardando_analise", "lista_espera", "deferido",
                       "aguardando_comprovantes", "concluido", "indeferido"):
            assert int(cartoes[status]) == banco.get(status, 0), status


def test_grafico_por_dia_bate_com_o_banco(fluxo, admin, db):
    p = fluxo("rascunho")
    # Dia no fuso do site (post_date), não no do servidor do banco.
    hoje = db.scalar(f"SELECT DATE(post_date) FROM {db.p}posts WHERE ID = {p.id}")
    no_dia = int(db.scalar(
        f"SELECT COUNT(*) FROM {db.p}posts WHERE post_type='inscricao_festa' AND post_status='publish' "
        f"AND DATE(post_date) = '{hoje}'"
    ))
    html = admin.pagina("festa-dashboard").text
    linha = soup(html).select_one(f'tr[data-dia="{hoje}"] td')
    assert linha is not None and int(linha.get_text(strip=True)) == no_dia
    svg = soup(html).select_one("svg.festa-dash-svg")
    assert svg is not None and svg.get("role") == "img" and svg.find("desc")
    assert "<script" not in str(svg)


def test_dashboard_nao_mostra_dados_pessoais(fluxo, comissao, admin, db, portal):
    pessoas = [fluxo("aguardando_analise"), fluxo("concluido")]
    assert msg_do_redirect(comissao.indeferir(pessoas[0].id, JUSTIFICATIVA)) == "indeferido"

    for html in (admin.pagina("festa-dashboard").text, portal("painel").tela("dashboard").text):
        for p in pessoas:
            formatado = f"{p.cpf[:3]}.{p.cpf[3:6]}.{p.cpf[6:9]}-{p.cpf[9:]}"
            mascarado = f"{p.cpf[3:6]}.{p.cpf[6:9]}"
            for dado in (p.nome, p.cpf, formatado, mascarado, db.meta(p.id, "_email"),
                         db.meta(p.id, "_telefone"), JUSTIFICATIVA):
                assert dado not in html, f"dado pessoal no dashboard: {dado}"
        assert "@example.test" not in html


# -- LGPD ------------------------------------------------------------------------

def test_expurgo_lgpd_inclui_indeferidas_antigas(fluxo, comissao, admin, db):
    indeferida, em_analise = fluxo("aguardando_analise"), fluxo("aguardando_analise")
    assert msg_do_redirect(comissao.indeferir(indeferida.id, JUSTIFICATIVA)) == "indeferido"
    for p in (indeferida, em_analise):
        db.query(
            f"UPDATE {db.p}posts SET post_date = NOW() - INTERVAL 400 DAY, "
            f"post_date_gmt = UTC_TIMESTAMP() - INTERVAL 400 DAY WHERE ID = {p.id}"
        )
    try:
        url = f"{ADMIN}admin.php?page=festa_lgpd_anonymizer"
        html = admin.http.get(url).text
        form = soup(html).find("input", attrs={"name": "festa_lgpd_purge_nonce"}).find_parent("form")
        r = admin.http.post(url, data=campos_do_form(form))
        assert r.status_code == 200 and "Lote concluído" in r.text

        assert db.meta(indeferida.id, "_lgpd_purged") == "1"
        assert db.meta(indeferida.id, "_nome_responsavel") == "[ANONIMIZADO_LGPD]"
        assert db.meta(indeferida.id, "_indeferimento_motivo") is None
        assert JUSTIFICATIVA not in " ".join(t[2] for t in db.transicoes(indeferida.id))
        assert db.status(indeferida.id) == "indeferido", "o histórico administrativo fica"

        assert db.meta(em_analise.id, "_lgpd_purged") is None, "inscrição em análise não pode ser anonimizada"
        assert db.meta(em_analise.id, "_nome_responsavel") == em_analise.nome
    finally:
        _apagar_inscricoes(indeferida.id)
        db.query(f"UPDATE {db.p}posts SET post_date = NOW(), post_date_gmt = UTC_TIMESTAMP() WHERE ID = {em_analise.id}")
