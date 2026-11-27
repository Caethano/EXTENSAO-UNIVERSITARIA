"""
Portal externo: contas próprias (fora do WordPress), cada uma só com as
permissões marcadas pelo administrador.
"""

import hashlib

import pytest

from festa import EquipePortal, achar_form, msg_do_redirect, q, soup


@pytest.fixture
def anonimo(amb, limites_zerados) -> EquipePortal:
    return EquipePortal(amb["portal_url"])


def _tela_de_login(html: str) -> bool:
    return achar_form(html, "entrar") is not None


# -- login ---------------------------------------------------------------------

def test_login_valido_abre_o_portal(portal):
    conta = portal("comissao")
    r = conta.tela("inscricoes")
    assert r.status_code == 200
    assert not _tela_de_login(r.text)


def test_login_com_senha_errada(anonimo, amb):
    r = anonimo.entrar(amb["portal"]["leitor"]["login"], "senha-errada-123")
    assert r.status_code == 401
    assert _tela_de_login(r.text)


def test_resposta_igual_para_usuario_inexistente(anonimo, amb):
    errada = anonimo.entrar(amb["portal"]["leitor"]["login"], "senha-errada-123")
    inexistente = anonimo.entrar("festa-t-nao-existe", "senha-errada-123")
    msg = lambda r: soup(r.text).select_one(".notice-error, [role=alert]").get_text(strip=True)
    assert (errada.status_code, msg(errada)) == (inexistente.status_code, msg(inexistente))


def test_login_sem_token_da_pagina_e_recusado(anonimo, amb):
    u = amb["portal"]["leitor"]
    r = anonimo.entrar(u["login"], u["senha"], pre="")
    assert r.status_code == 400


def test_login_vindo_de_outro_site_e_recusado(anonimo, amb):
    u = amb["portal"]["leitor"]
    r = anonimo.entrar(u["login"], u["senha"], headers={"Origin": "https://site-malicioso.example"})
    assert r.status_code == 403


def test_cinco_erros_bloqueiam_a_conta(anonimo, amb, db):
    u = amb["portal"]["leitor"]
    for _ in range(5):
        assert anonimo.entrar(u["login"], "senha-errada-123").status_code == 401
    assert anonimo.entrar(u["login"], u["senha"]).status_code == 401
    assert int(db.scalar(f"SELECT locked_until FROM {db.p}festa_portal_users WHERE login={q(u['login'])}")) > 0


# -- sessão ------------------------------------------------------------------

def test_banco_guarda_so_o_hash_do_token(portal, db):
    conta = portal("leitor")
    token = conta.http.cookies.get("festa_portal")
    assert token
    sessoes = f"{db.p}festa_portal_sessions"
    assert db.scalar(f"SELECT COUNT(*) FROM {sessoes} WHERE token_hash={q(hashlib.sha256(token.encode()).hexdigest())}") == "1"
    assert db.scalar(f"SELECT COUNT(*) FROM {sessoes} WHERE token_hash={q(token)}") == "0"


def test_sair_invalida_o_cookie(portal, amb):
    conta = portal("leitor")
    token = conta.http.cookies.get("festa_portal")
    assert conta.post("sair").status_code == 303

    outro = EquipePortal(amb["portal_url"])
    outro.http.cookies.set("festa_portal", token)
    assert _tela_de_login(outro.tela("inscricoes").text)


def test_cookie_em_outro_navegador_nao_vale(portal, amb):
    conta = portal("leitor")
    ladrao = EquipePortal(amb["portal_url"])
    ladrao.http.headers["User-Agent"] = "navegador-do-ladrao/1.0"
    ladrao.http.cookies.set("festa_portal", conta.http.cookies.get("festa_portal"))
    assert _tela_de_login(ladrao.tela("inscricoes").text)


def test_conta_desativada_perde_a_sessao_na_hora(portal, amb, db):
    conta = portal("leitor")
    login = amb["portal"]["leitor"]["login"]
    db.query(f"UPDATE {db.p}festa_portal_users SET ativo=0 WHERE login={q(login)}")
    try:
        assert _tela_de_login(conta.tela("inscricoes").text)
    finally:
        db.query(f"UPDATE {db.p}festa_portal_users SET ativo=1 WHERE login={q(login)}")


# -- CSRF e origem ---------------------------------------------------------

def test_acao_sem_token_csrf_e_recusada(fluxo, portal, db):
    p = fluxo("aguardando_analise")
    conta = portal("comissao")
    r = conta.post("festa_notes", {"id": p.id, "observacoes": "forjado"}, csrf="token-falso")
    assert r.status_code == 403
    assert db.meta(p.id, "_observacoes") is None


@pytest.mark.parametrize("cabecalho", [
    {"Origin": "https://site-malicioso.example"},
    {"Referer": "https://site-malicioso.example/pagina"},
    {"Sec-Fetch-Site": "cross-site"},
])
def test_acao_vinda_de_outro_site_e_recusada(fluxo, portal, db, cabecalho):
    p = fluxo("aguardando_analise")
    conta = portal("comissao")
    r = conta.post("festa_notes", {"id": p.id, "observacoes": "csrf"}, headers=cabecalho)
    assert r.status_code == 403
    assert db.meta(p.id, "_observacoes") is None


def test_nada_muda_por_get(fluxo, portal, db):
    p = fluxo("aguardando_analise")
    conta = portal("comissao")
    conta.baixar(festa_acao="festa_notes", id=p.id, observacoes="via-get", _csrf=conta.csrf())
    assert db.meta(p.id, "_observacoes") is None


# -- permissões por conta ----------------------------------------------------

def test_comissao_pelo_portal_conduz_o_fluxo_e_fica_registrada(fluxo, portal, db):
    p = fluxo("aguardando_analise")
    conta = portal("comissao")
    assert msg_do_redirect(conta.deferir(p.id)) == "deferido"
    assert msg_do_redirect(conta.tenda(p.id, "P-01")) == "tenda"
    assert db.status(p.id) == "aguardando_comprovantes"
    autores = [r[0] for r in db.query(
        f"SELECT actor FROM {db.p}festa_audit_log WHERE inscricao_id={p.id} AND action='tenda'"
    )]
    assert autores and autores[-1].endswith("(portal)")


@pytest.mark.parametrize("acao,dados", [
    ("festa_tenda", {"numero_tenda": "X-1"}),
    ("festa_notes", {"observacoes": "leitor"}),
    ("festa_senha", {}),
    ("festa_delete", {}),
    ("festa_save_settings", {"aberto": "0"}),
])
def test_conta_so_de_leitura_nao_altera_nada(fluxo, portal, db, acao, dados):
    p = fluxo("deferido")
    conta = portal("leitor")
    antes = db.retrato(p.id)
    r = conta.post(acao, {"id": p.id, **dados})
    assert r.status_code == 403
    assert db.retrato(p.id) == antes


def test_conta_so_de_leitura_nao_exporta(portal):
    conta = portal("leitor")
    assert conta.baixar(baixar="festa_export").status_code == 403
    assert conta.baixar(baixar="festa_export_pdf").status_code == 403


@pytest.mark.parametrize("tela,codigo", [("inscricoes", 403), ("auditoria", 403), ("config", 403), ("colchoes", 200)])
def test_conta_da_defesa_so_ve_a_busca(portal, tela, codigo):
    assert portal("defesa").tela(tela).status_code == codigo


def test_conta_da_defesa_confirma_colchoes_pelo_portal(fluxo, portal, db):
    p = fluxo("recibo_aprovado")
    conta = portal("defesa")
    conta.confirmar_colchoes(p.cpf)
    assert db.meta(p.id, "_colchoes_confirmados") == "1"
    assert db.status(p.id) == "concluido"


def test_comissao_do_portal_nao_acessa_configuracoes(portal):
    assert portal("comissao").tela("config").status_code == 403


# -- troca obrigatória de senha ----------------------------------------------

def test_conta_nova_e_obrigada_a_trocar_a_senha(portal):
    conta = portal("novo")
    assert "tela=conta" in conta.ultima.headers["Location"]
    r = conta.tela("inscricoes")
    assert r.status_code == 303 and "tela=conta" in r.headers["Location"]


def test_troca_de_senha_libera_a_conta_e_invalida_a_antiga(portal, amb, db):
    u = amb["portal"]["novo"]
    tabela = f"{db.p}festa_portal_users"
    hash_original = db.scalar(f"SELECT pass_hash FROM {tabela} WHERE login={q(u['login'])}")
    try:
        conta = portal("novo")
        nova = "Outra senha bem longa e minha 2026!"
        r = conta.post("conta_senha", {"senha_atual": u["senha"], "senha_nova": nova, "senha_nova2": nova}, tela="conta")
        assert r.status_code == 303 and "msg=senha" in r.headers["Location"]
        assert conta.tela("inscricoes").status_code == 200

        anonimo = EquipePortal(amb["portal_url"])
        assert anonimo.entrar(u["login"], u["senha"]).status_code == 401
        assert anonimo.entrar(u["login"], nova).status_code == 303
    finally:
        db.query(
            f"UPDATE {tabela} SET pass_hash={q(hash_original)}, must_change=1, failed_count=0, locked_until=0 "
            f"WHERE login={q(u['login'])}"
        )


@pytest.mark.parametrize("nova", ["curta", "festa-t-novo-2026-abc", "aaaaaaaaaaaaaaaa"])
def test_portal_recusa_senha_fraca(portal, amb, nova):
    u = amb["portal"]["novo"]
    conta = portal("novo")
    r = conta.post("conta_senha", {"senha_atual": u["senha"], "senha_nova": nova, "senha_nova2": nova}, tela="conta")
    assert r.status_code == 200, "a troca deveria ser recusada e a tela reexibida"
    assert conta.tela("inscricoes").status_code == 303, "a conta continuou obrigada a trocar a senha"
