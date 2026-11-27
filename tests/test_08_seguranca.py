"""
Regressões de segurança: cada falha encontrada na auditoria (corrigida na
versão 2.0.0) tem aqui o teste do comportamento seguro e, quando cabe, o do
uso legítimo que precisa continuar funcionando.

Pré-condições usam `requer` (pytest.fail) para separar "o cenário não se
montou" de "a proteção falhou".
"""

import re
import threading
import xmlrpc.client
from urllib.parse import parse_qs, urlparse

import pytest
import requests

from festa import (
    ADMIN_POST, AJAX, BASE, EquipePortal, Participante, achar_form, campos_do_form, msg_do_redirect,
    post_de_outra_rede, soup, url_da_pasta_privada, wp_eval,
)

pytestmark = pytest.mark.seguranca


def requer(condicao: bool, msg: str) -> None:
    if not condicao:
        pytest.fail(f"pré-condição do teste não atendida: {msg}")


# -- Senha curta e senha nova gerada pela Comissão --------------------------------

# Abaixo do mínimo de 8 caracteres: nunca entra, mesmo que o hash gravado confira.
SENHA_CURTA = "abc12"
SENHA_VALIDA = "Frase de controle 2026"


def _gravar_senha(p, senha: str) -> None:
    """Grava no banco o hash de `senha` direto, sem a validação de tamanho da troca de senha."""
    proc = wp_eval(
        "<?php update_post_meta((int) $args[0], '_senha_hash', wp_hash_password((string) $args[1]));",
        str(p.id), senha,
    )
    requer(proc.returncode == 0, f"não foi possível gravar a senha: {proc.stderr}")


def _senha_do_aviso(comissao, r) -> str:
    """Senha nova mostrada uma única vez à Comissão, depois de "Gerar nova senha"."""
    requer(r.status_code == 302, f"gerar nova senha respondeu {r.status_code}")
    tag = soup(comissao.http.get(r.headers["Location"]).text).select_one("code.festa-flash-pass")
    requer(tag is not None, "a senha nova não apareceu para a Comissão")
    return tag.get_text(strip=True)


def _sessao_aberta(p) -> bool:
    return f"Protocolo <strong>#{p.id}" in p.http.get(p.paginas["painel"]).text


def test_senha_curta_nao_entra(fluxo):
    p = fluxo("aguardando_analise")
    p.logout()
    _gravar_senha(p, SENHA_CURTA)
    assert p.login(SENHA_CURTA).status == 401, "entrou com uma senha abaixo do mínimo"
    # Controle: o mesmo caminho com uma senha válida entra (a recusa é pelo tamanho).
    _gravar_senha(p, SENHA_VALIDA)
    assert p.login(SENHA_VALIDA).ok, "a senha válida gravada do mesmo jeito deveria entrar"


def test_senha_anterior_deixa_de_valer_depois_da_nova(fluxo, comissao):
    p = fluxo("aguardando_analise")
    p.logout()
    antiga = p.senha
    requer(p.login(antiga).ok, "o participante não entrou com a senha que já tinha")
    requer(_sessao_aberta(p), "a sessão do participante não abriu")

    nova = _senha_do_aviso(comissao, comissao.nova_senha(p.id))
    assert nova and nova != antiga
    assert not _sessao_aberta(p), "a sessão aberta com a senha anterior continuou valendo"
    assert p.login(antiga).status == 401, "a senha anterior continuou valendo"
    assert p.login(nova).ok, "o participante não entrou com a senha nova"


def test_senha_curta_volta_a_entrar_com_senha_nova_da_comissao(fluxo, comissao):
    p = fluxo("aguardando_analise")
    p.logout()
    _gravar_senha(p, SENHA_CURTA)
    requer(p.login(SENHA_CURTA).status == 401, "a senha curta deveria estar recusada")

    nova = _senha_do_aviso(comissao, comissao.nova_senha(p.id))
    assert len(nova) >= 8
    assert p.login(nova).ok, "o participante não entrou com a senha nova da Comissão"

    r = comissao.http.get(comissao.url_inscricao(p.id))
    assert r.status_code == 200 and f"Inscrição #{p.id}" in r.text, "a tela da inscrição não abriu"


# -- Descobrir CPFs pelo pré-cadastro ---------------------------------------------

def test_pre_cadastro_limita_consultas_de_cpf_existente(fluxo, novo_participante):
    alvo = fluxo("rascunho")
    curioso = novo_participante(cpf=alvo.cpf)
    codigos = [curioso.pre_cadastro().status for _ in range(15)]
    requer(codigos[0] == 409, f"primeira resposta inesperada: {codigos[0]}")
    assert 429 in codigos, f"15 consultas do mesmo CPF e nenhuma barrada: {codigos}"


# -- Chaves fora do banco ----------------------------------------------------------

def test_chaves_de_criptografia_fora_do_banco(db):
    n = db.scalar(
        f"SELECT COUNT(*) FROM {db.p}options WHERE option_name IN ('festa_enc_key','festa_hmac_key')"
    )
    assert n == "0", "as chaves estão em wp_options: um dump do banco decifra todos os CPFs"


def test_arquivo_de_chaves_nao_abre_pelo_navegador(amb):
    base = url_da_pasta_privada(amb)
    if base is None:
        pytest.skip("a pasta privada fica fora da raiz do site: não existe URL para ela")
    r = requests.get(f"{base}/festa-chaves.php")
    assert r.status_code == 403 and "enc" not in r.text


# -- Bloqueio de login ----------------------------------------------------------------

def test_atacante_de_outra_rede_nao_tranca_o_dono(fluxo):
    p = fluxo("rascunho")
    p.logout()
    for i in range(6):
        r = post_de_outra_rede(
            "/wp-admin/admin-ajax.php",
            {"action": "festa_auth_login", "nonce": p.nonce(), "cpf": p.cpf, "senha": f"chute-{i}"},
        )
        requer(r.status == 401, f"tentativa do atacante respondeu {r.status}")

    r = post_de_outra_rede(
        "/wp-admin/admin-ajax.php",
        {"action": "festa_auth_login", "nonce": p.nonce(), "cpf": p.cpf, "senha": p.senha},
    )
    assert r.status == 401, "a rede do atacante deveria ficar bloqueada mesmo com a senha certa"
    assert p.login().ok, "o dono, em outra rede, ficou trancado fora da própria inscrição"


@pytest.mark.lento
def test_bloqueio_do_participante_resiste_a_tentativas_simultaneas(fluxo):
    p = fluxo("rascunho")
    p.logout()
    nonce = p.nonce()
    largada = threading.Barrier(12)
    codigos = []

    def tentar(i):
        s = requests.Session()
        largada.wait()
        r = s.post(AJAX, data={"action": "festa_auth_login", "nonce": nonce, "cpf": p.cpf, "senha": f"chute-{i}"})
        codigos.append(r.status_code)

    threads = [threading.Thread(target=tentar, args=(i,)) for i in range(12)]
    for t in threads:
        t.start()
    for t in threads:
        t.join()

    requer(all(c == 401 for c in codigos), f"respostas inesperadas: {codigos}")
    assert p.login().status == 401, "12 senhas erradas simultâneas e a inscrição não bloqueou"


@pytest.mark.lento
def test_bloqueio_do_portal_resiste_a_tentativas_simultaneas(amb, db, limites_zerados):
    u = amb["portal"]["leitor"]
    contas = [EquipePortal(amb["portal_url"]) for _ in range(10)]
    pres = [c.pre_token() for c in contas]
    largada = threading.Barrier(len(contas))

    def tentar(conta, pre):
        largada.wait()
        conta.entrar(u["login"], "senha-errada-xyz", pre=pre)

    threads = [threading.Thread(target=tentar, args=par) for par in zip(contas, pres)]
    for t in threads:
        t.start()
    for t in threads:
        t.join()

    bloqueada = int(db.scalar(f"SELECT locked_until FROM {db.p}festa_portal_users WHERE login='{u['login']}'"))
    assert bloqueada > 0, "10 senhas erradas simultâneas e a conta não bloqueou"
    assert EquipePortal(amb["portal_url"]).entrar(u["login"], u["senha"]).status_code == 401


# -- XML-RPC e permissões da Comissão --------------------------------------------

def test_comissao_nao_cria_inscricao_pelo_xmlrpc(amb):
    u = amb["wp"]["comissao"]
    srv = xmlrpc.client.ServerProxy(f"{BASE}/xmlrpc.php")
    try:
        criado = srv.wp.newPost(1, u["login"], u["senha"], {
            "post_type": "inscricao_festa", "post_status": "publish", "post_title": "Teste Auto via XML-RPC",
        })
    except xmlrpc.client.Fault:
        criado = None
    assert criado is None, f"inscrição #{criado} criada fora do fluxo, sem CPF nem pré-cadastro"


def test_nem_o_administrador_cria_inscricao_pelo_xmlrpc(amb):
    u = amb["wp"]["admin"]
    srv = xmlrpc.client.ServerProxy(f"{BASE}/xmlrpc.php")
    with pytest.raises(xmlrpc.client.Fault):
        srv.wp.newPost(1, u["login"], u["senha"], {
            "post_type": "inscricao_festa", "post_status": "publish", "post_title": "Teste Auto via XML-RPC (admin)",
        })


def test_comissao_nao_le_posts_privados_do_site(comissao, amb):
    url = f"{BASE}/?p={amb['private_post']}"
    requer("conteudo-privado-festa-teste" not in requests.get(url).text, "visitante não deveria ver o post privado")
    assert "conteudo-privado-festa-teste" not in comissao.http.get(url).text


def test_comissao_nao_escreve_posts_no_site(comissao):
    r = comissao.http.get(f"{BASE}/wp-admin/post-new.php", allow_redirects=False)
    assert r.status_code == 403, f"tela de novo post abriu para a Comissão (HTTP {r.status_code})"


def test_comissao_continua_com_o_painel_completo(fluxo, comissao):
    """Tirar as permissões nativas não pode tirar nada do painel."""
    p = fluxo("aguardando_analise")
    assert comissao.pagina("festa-inscricoes").status_code == 200
    assert msg_do_redirect(comissao.deferir(p.id)) == "deferido"


# -- Sessões depois da troca de senha -----------------------------------------------

def test_nova_senha_pela_equipe_derruba_a_sessao_aberta(fluxo, comissao):
    p = fluxo("aguardando_analise")
    requer(p.logado(), "participante deveria estar logado")
    r = comissao.nova_senha(p.id)
    requer(r.status_code == 302, "a equipe deveria conseguir gerar a senha")
    comissao.http.get(r.headers["Location"])
    assert not p.logado(), "quem estava com a sessão aberta continuou dentro"


def test_troca_de_senha_derruba_as_outras_sessoes(fluxo, amb, db):
    p = fluxo("rascunho")
    outro = Participante(amb["pages"], db, nome=p.nome, cpf=p.cpf, senha=p.senha)
    requer(outro.login().ok, "segunda sessão deveria abrir")
    requer(p.trocar_senha(p.senha, "Frase nova e minha 2026").ok, "troca de senha deveria funcionar")
    assert not outro.logado(), "a outra sessão continuou valendo depois da troca"


def test_quem_troca_a_senha_continua_usando_a_pagina_aberta(fluxo):
    """A sessão é renovada com o mesmo token CSRF: a página aberta segue funcionando."""
    p = fluxo("aguardando_analise")
    csrf_da_pagina = p.csrf()
    requer(p.trocar_senha(p.senha, "Frase nova e minha 2026").ok, "troca de senha deveria funcionar")
    assert p.logado()
    assert p.enviar_documento("ficha_assinada", csrf=csrf_da_pagina).ok


# -- Senhas geradas pela equipe ----------------------------------------------------

def test_senha_gerada_pela_equipe_nao_fica_em_texto_no_banco(fluxo, comissao, db):
    p = fluxo("aguardando_analise")
    r = comissao.nova_senha(p.id)
    guardado = " ".join(row[0] for row in db.query(
        f"SELECT option_value FROM {db.p}options WHERE option_name LIKE '_transient_festa_flash_senha_%'"
    ))
    senha = soup(comissao.http.get(r.headers["Location"]).text).select_one("code.festa-flash-pass").get_text(strip=True)
    requer(bool(guardado) and bool(senha), "a senha deveria estar guardada até a tela abrir")
    assert senha not in guardado, "a senha nova ficou em claro em wp_options"


def test_senha_de_conta_do_portal_nao_fica_em_texto_no_banco(admin, db):
    url = f"{BASE}/wp-admin/admin.php?page=festa-portal"
    form = achar_form(admin.http.get(url).text, "festa_portal_user_create")
    dados = campos_do_form(form)
    dados.update({"nome": "Conta Flash", "login": "festa-t-flash", "email": "", "perms[]": ["ver"]})
    r = admin.http.post(ADMIN_POST, data=dados, allow_redirects=False)
    guardado = db.scalar(
        f"SELECT option_value FROM {db.p}options WHERE option_name = '_transient_festa_portal_flash_{admin.user_id}'"
    ) or ""
    senha = soup(admin.http.get(r.headers["Location"]).text).select_one("code.festa-flash-pass").get_text(strip=True)
    requer(bool(guardado) and bool(senha), "a senha deveria estar guardada até a tela abrir")
    assert senha not in guardado


# -- Busca por CPF ---------------------------------------------------------------------

def test_busca_da_comissao_nao_poe_cpf_na_url(fluxo, comissao):
    p = fluxo("aguardando_analise")
    html = comissao.http.get(comissao.url_lista()).text
    form = soup(html).find("input", id="festa-search").find_parent("form")
    assert form.get("method", "get").lower() == "post"

    formatado = f"{p.cpf[:3]}.{p.cpf[3:6]}.{p.cpf[6:9]}-{p.cpf[9:]}"
    r = comissao.enviar_form(html, "festa_busca", comissao.url_lista(), {"s": formatado})
    destino = r.headers.get("Location", "")
    assert p.cpf not in destino and formatado not in destino and "%2E" not in destino, f"CPF no endereço: {destino}"
    assert re.fullmatch(r"[a-f0-9]{64}", parse_qs(urlparse(destino).query).get("cpf", [""])[0])

    lista = comissao.http.get(destino).text
    assert p.nome in lista, "a busca por CPF deveria encontrar a inscrição"


def test_busca_por_nome_continua_funcionando(fluxo, comissao):
    p = fluxo("aguardando_analise")
    html = comissao.http.get(comissao.url_lista()).text
    r = comissao.enviar_form(html, "festa_busca", comissao.url_lista(), {"s": p.nome})
    assert p.nome in comissao.http.get(r.headers["Location"]).text


def test_busca_por_cpf_no_portal(fluxo, portal):
    p = fluxo("aguardando_analise")
    conta = portal("comissao")
    html = conta.tela("inscricoes").text
    r = conta.enviar_form(html, "festa_busca", conta.url_lista(), {"s": p.cpf})
    assert r.status_code == 303 and p.cpf not in r.headers["Location"]
    assert p.nome in conta.http.get(r.headers["Location"]).text


# -- Portal: Defesa Civil, auditoria, troca obrigatória ------------------------------

def test_defesa_no_portal_so_abre_comprovante_que_buscou(fluxo, portal, db):
    p = fluxo("concluido")
    requer(bool(db.meta(p.id, "_upload_comprovante_doacao")), "comprovante de doação deveria existir")
    conta = portal("defesa")
    r = conta.baixar(baixar="festa_arquivo", doc="comprovante_doacao", id=p.id)
    assert r.status_code == 403, "comprovante (nome, CPF mascarado, grupo) aberto sem busca pelo CPF"


def test_defesa_abre_o_comprovante_depois_de_buscar(fluxo, portal):
    p = fluxo("concluido")
    conta = portal("defesa")
    html = conta.buscar_cpf(p.cpf)
    requer("comprovante" in html.lower(), "o resultado da busca deveria oferecer o comprovante")
    r = conta.baixar(baixar="festa_arquivo", doc="comprovante_doacao", id=p.id)
    assert r.status_code == 200 and p.nome in r.text


def test_defesa_no_wp_admin_abre_o_comprovante_que_buscou(fluxo, defesa):
    p = fluxo("concluido")
    html = defesa.buscar_cpf(p.cpf)
    link = next(a["href"] for a in soup(html).find_all("a", href=True) if "festa_arquivo" in a["href"])
    r = defesa.http.get(link)
    assert r.status_code == 200 and p.nome in r.text


def test_auditoria_do_portal_nao_mostra_logins_do_wordpress(fluxo, portal, amb):
    p = fluxo("deferido")
    html = portal("comissao").tela("auditoria").text
    requer(f"view={p.id}" in html, "a ação da Comissão deveria aparecer na auditoria")
    assert amb["wp"]["comissao"]["login"] not in html
    assert "Equipe (wp-admin)" in html


def test_auditoria_do_wp_admin_continua_mostrando_quem_fez(fluxo, admin, amb):
    fluxo("deferido")
    assert amb["wp"]["comissao"]["login"] in admin.pagina("festa_audit_log").text


def test_troca_obrigatoria_no_portal_nao_pode_ser_contornada(fluxo, portal, amb):
    p = fluxo("aguardando_analise")
    conta = portal("novo")
    requer(conta.tela("inscricoes").status_code == 303, "conta nova deveria ser mandada trocar a senha")
    u = amb["portal"]["novo"]
    r = conta.post("conta_senha", {"senha_atual": u["senha"], "senha_nova": "curta", "senha_nova2": "curta"})
    assert p.nome not in r.text, "a lista de inscrições apareceu sem a senha provisória ter sido trocada"
    assert "Minha conta" in r.text


# -- Páginas do participante ---------------------------------------------------------

@pytest.mark.parametrize("pagina", ["pre", "inscricao", "painel"])
def test_paginas_do_participante_bloqueiam_iframe(amb, pagina):
    r = requests.get(amb["pages"][pagina])
    assert r.headers.get("X-Frame-Options", "").upper() == "SAMEORIGIN"
    assert "frame-ancestors 'self'" in r.headers.get("Content-Security-Policy", "")


def test_mensagem_arbitraria_na_url_nao_aparece(amb):
    texto = "Sua inscricao foi cancelada. Ligue 0800 000 0000"
    r = requests.get(amb["pages"]["pre"], params={"festa_erro": texto, "festa_sig": "0" * 32})
    assert texto not in r.text


def test_aviso_legitimo_do_envio_sem_javascript_aparece(amb, limites_zerados):
    """Sem JavaScript, o erro volta na URL assinado pelo site e é mostrado."""
    s = requests.Session()
    html = s.get(amb["pages"]["pre"]).text
    form = achar_form(html, "festa_auth_ini_inscricao")
    dados = campos_do_form(form)
    dados.update({"cpf": "111.111.111-11", "nome": "Teste Auto Sem JS"})
    r = s.post(ADMIN_POST, data=dados, allow_redirects=False)
    destino = r.headers.get("Location", "")
    requer("festa_sig=" in destino, f"o erro deveria voltar assinado: {destino}")
    assert "CPF inválido" in s.get(destino).text
