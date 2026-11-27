"""
Permissões no wp-admin: matriz perfil × ação.

Cada ação negada é tentada com um nonce VÁLIDO da sessão do próprio usuário,
gerado pelo WordPress: a recusa tem de vir da falta de permissão, não do nonce.
Depois de cada tentativa, o estado da inscrição-alvo é comparado com o de antes.
"""

import pytest
import requests

from festa import ADMIN, ADMIN_POST, BASE, Participante, achar_form, campos_do_form, msg_do_redirect, pdf

# ação → (método, ação do nonce, dados extras)
ACOES = {
    "festa_busca":              ("post", "festa_busca", {"s": "busca"}),
    "festa_deferir":            ("post", "festa_deferir_{id}", {}),
    "festa_tenda":              ("post", "festa_tenda_{id}", {"numero_tenda": "HACK-1"}),
    "festa_recibo":             ("post", "festa_recibo_{id}", {}),
    "festa_colchoes":           ("post", "festa_colchoes_{id}", {}),
    "festa_notes":              ("post", "festa_notes_{id}", {"observacoes": "HACK"}),
    "festa_contato":            ("post", "festa_contato_{id}", {"email": "hack@example.test", "telefone": "(11) 90000-0000"}),
    "festa_senha":              ("post", "festa_senha_{id}", {}),
    "festa_anexar":             ("post", "festa_anexar_{id}", {"doc": "identidade"}),
    "festa_delete":             ("post", "festa_delete", {}),
    "festa_bulk":               ("post", "festa_bulk", {"bulk_action": "delete"}),
    "festa_save_settings":      ("post", "festa_settings", {"aberto": "1"}),
    "festa_portal_settings":    ("post", "festa_portal_admin", {"enabled": "1"}),
    "festa_portal_user_create": ("post", "festa_portal_admin", {"nome": "Hack", "login": "festa-t-hack", "perms[]": "config"}),
    "festa_arquivo":            ("get", "festa_arquivo_{id}", {"doc": "ficha_gerada"}),
    "festa_zip":                ("get", "festa_zip_{id}", {}),
    "festa_export":             ("get", "festa_export", {}),
    "festa_export_pdf":         ("get", "festa_export_pdf", {}),
}

PERMITIDAS = {
    "comissao":  {"festa_busca", "festa_deferir", "festa_tenda", "festa_recibo", "festa_notes", "festa_contato",
                  "festa_senha", "festa_anexar", "festa_arquivo", "festa_zip", "festa_export", "festa_export_pdf"},
    "defesa":    {"festa_colchoes"},
    "assinante": set(),
}

NEGADAS = [(papel, acao) for papel in PERMITIDAS for acao in ACOES if acao not in PERMITIDAS[papel]]


@pytest.fixture(scope="module")
def alvo(amb, db, comissao):
    """Inscrição em 'aguardando comprovantes' com recibo enviado: todas as ações se aplicam."""
    db.zerar_limites()
    p = Participante(amb["pages"], db)
    assert p.pre_cadastro().ok and p.finalizar().ok
    assert msg_do_redirect(comissao.deferir(p.id)) == "deferido"
    assert msg_do_redirect(comissao.tenda(p.id)) == "tenda"
    assert p.enviar_documento("recibo_tenda").ok
    return p


@pytest.fixture(scope="module")
def nonces(alvo, comissao, defesa, assinante):
    acoes = sorted({n.format(id=alvo.id) for _, n, _ in ACOES.values()})
    return {
        "comissao": comissao.nonces(acoes),
        "defesa": defesa.nonces(acoes),
        "assinante": assinante.nonces(acoes),
    }


def _tentar(usuario, acao: str, alvo_id: int, nonce: str) -> requests.Response:
    metodo, _, extra = ACOES[acao]
    dados = {"id": alvo_id, "_wpnonce": nonce, **extra}
    if acao == "festa_bulk":
        dados["ids[]"] = alvo_id
    if metodo == "get":
        return usuario.http.get(ADMIN_POST, params={"action": acao, **dados}, allow_redirects=False)
    files = {"documento": pdf()} if acao == "festa_anexar" else None
    return usuario.http.post(ADMIN_POST, data={"action": acao, **dados}, files=files, allow_redirects=False)


@pytest.mark.parametrize("papel,acao", NEGADAS, ids=[f"{p}-{a}" for p, a in NEGADAS])
def test_acao_negada_mesmo_com_nonce_valido(request, papel, acao, alvo, nonces, db):
    usuario = request.getfixturevalue(papel)
    nonce = nonces[papel][ACOES[acao][1].format(id=alvo.id)]
    antes = db.retrato(alvo.id)

    r = _tentar(usuario, acao, alvo.id, nonce)

    assert r.status_code == 403, f"{papel} executou {acao}: HTTP {r.status_code} {r.headers.get('Location', '')}"
    assert db.retrato(alvo.id) == antes


@pytest.mark.parametrize("acao", list(ACOES))
def test_visitante_anonimo_nao_executa_nada(acao, alvo, db):
    antes = db.retrato(alvo.id)
    metodo, _, extra = ACOES[acao]
    dados = {"action": acao, "id": alvo.id, **extra}
    r = requests.get(ADMIN_POST, params=dados, allow_redirects=False) if metodo == "get" \
        else requests.post(ADMIN_POST, data=dados, allow_redirects=False)
    assert r.status_code in (400, 403)
    assert db.retrato(alvo.id) == antes


def test_controle_positivo_nonce_gerado_e_aceito(alvo, comissao, nonces, db):
    """Garante que a matriz acima testa permissão: o mesmo tipo de nonce funciona para quem pode."""
    r = _tentar(comissao, "festa_notes", alvo.id, nonces["comissao"][f"festa_notes_{alvo.id}"])
    assert msg_do_redirect(r) == "notes"
    assert db.meta(alvo.id, "_observacoes") == "HACK"


# -- nonce e método ------------------------------------------------------------

def test_acao_sem_nonce_e_recusada(alvo, comissao, db):
    antes = db.retrato(alvo.id)
    r = comissao.admin_post("festa_notes", {"id": alvo.id, "observacoes": "sem nonce"})
    assert r.status_code == 403
    assert db.retrato(alvo.id) == antes


def test_nonce_de_outra_inscricao_nao_vale(fluxo, comissao, db):
    a, b = fluxo("aguardando_analise"), fluxo("aguardando_analise")
    nonce_a = comissao.nonces([f"festa_notes_{a.id}"])[f"festa_notes_{a.id}"]
    r = comissao.admin_post("festa_notes", {"id": b.id, "_wpnonce": nonce_a, "observacoes": "replay"})
    assert r.status_code == 403
    assert db.meta(b.id, "_observacoes") is None


def test_nonce_de_outro_usuario_nao_vale(alvo, admin, comissao, db):
    nonce_admin = admin.nonces([f"festa_notes_{alvo.id}"])[f"festa_notes_{alvo.id}"]
    antes = db.retrato(alvo.id)
    r = comissao.admin_post("festa_notes", {"id": alvo.id, "_wpnonce": nonce_admin, "observacoes": "emprestado"})
    assert r.status_code == 403
    assert db.retrato(alvo.id) == antes


def test_acao_de_escrita_por_get_e_recusada(alvo, comissao, nonces, db):
    antes = db.retrato(alvo.id)
    r = comissao.admin_post("festa_notes", {
        "id": alvo.id, "_wpnonce": nonces["comissao"][f"festa_notes_{alvo.id}"], "observacoes": "via GET",
    }, metodo="get")
    assert r.status_code == 405
    assert db.retrato(alvo.id) == antes


# -- telas do wp-admin -------------------------------------------------------

TELAS = {
    "festa-inscricoes":       {"admin", "comissao"},
    "festa_audit_log":        {"admin", "comissao"},
    "festa-configuracoes":    {"admin"},
    "festa-portal":           {"admin"},
    "festa_lgpd_anonymizer":  {"admin"},
    "festa_busca_colchoes":   {"admin", "defesa"},
}
MATRIZ_TELAS = [(t, p) for t in TELAS for p in ("admin", "comissao", "defesa", "assinante")]


@pytest.mark.parametrize("tela,papel", MATRIZ_TELAS, ids=[f"{p}-{t}" for t, p in MATRIZ_TELAS])
def test_acesso_as_telas(request, tela, papel):
    r = request.getfixturevalue(papel).pagina(tela)
    esperado = 200 if papel in TELAS[tela] else 403
    assert r.status_code == esperado, f"{papel} em {tela}: HTTP {r.status_code}"


# -- Defesa Civil ------------------------------------------------------------

def test_defesa_ve_so_o_minimo_da_inscricao(fluxo, defesa, db):
    p = fluxo("aguardando_comprovantes")
    html = defesa.buscar_cpf(p.cpf)

    assert p.nome in html
    assert p.cpf not in html, "o CPF digitado voltou para a tela"
    assert f"{p.cpf[3:6]}.{p.cpf[6:9]}" in html, "o CPF deveria aparecer mascarado"
    email = db.meta(p.id, "_email")
    assert email and email not in html
    assert "(11) 98765-4321" not in html
    assert "festa_arquivo" not in html, "a Defesa Civil não deveria ver links de documentos nesta fase"


def test_defesa_busca_por_post_e_nao_poe_cpf_na_url(defesa):
    form = achar_form(defesa.http.get(defesa.url_colchoes()).text, "festa_colchoes_busca")
    assert form is not None and form.get("method", "").lower() == "post"


def test_defesa_busca_com_cpf_invalido(defesa):
    assert "CPF inválido" in defesa.buscar_cpf("123.456.789-00")


def test_confirmacao_dos_colchoes_presa_a_inscricao_buscada(fluxo, defesa, db):
    """O formulário de confirmação de X, com o id trocado para Y, é recusado."""
    x, y = fluxo("aguardando_comprovantes"), fluxo("aguardando_comprovantes")
    form = achar_form(defesa.buscar_cpf(x.cpf), "festa_colchoes")
    dados = campos_do_form(form)
    dados["id"] = str(y.id)
    r = defesa.http.post(ADMIN_POST, data=dados, allow_redirects=False)
    assert r.status_code == 403
    assert db.meta(y.id, "_colchoes_confirmados") is None
    assert db.meta(x.id, "_colchoes_confirmados") is None


def test_participante_logado_nao_vira_usuario_do_wp_admin(fluxo):
    p = fluxo("rascunho")
    r = p.http.get(ADMIN, allow_redirects=False)
    assert r.status_code == 302 and "wp-login.php" in r.headers["Location"]
    r = p.http.get(f"{ADMIN}admin.php?page=festa-inscricoes", allow_redirects=False)
    assert r.status_code == 302 and "wp-login.php" in r.headers["Location"]


def test_api_rest_nao_expoe_inscricoes(alvo):
    r = requests.get(f"{BASE}/?rest_route=/wp/v2/inscricao_festa")
    assert r.status_code == 404
    r = requests.get(f"{BASE}/?rest_route=/wp/v2/posts/{alvo.id}")
    assert r.status_code in (401, 403, 404)
