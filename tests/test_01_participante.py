"""
Participante: pré-cadastro, login, sessão, finalização, envios e troca de senha.
Tudo pelo front-end, como o navegador do participante faria.
"""

import pytest

from festa import HTML_COMO_JPG, PHP_COMO_PDF, PNG_OK, Participante, arquivo, pdf, soup


# -- pré-cadastro ------------------------------------------------------------

def test_pre_cadastro_cria_inscricao_mostra_senha_e_abre_sessao(novo_participante, db):
    p = novo_participante()
    r = p.pre_cadastro()

    assert r.ok, r.corpo
    assert len(p.senha) == 14 and p.senha.count("-") == 2, "senha provisória fora do formato XXXX-XXXX-XXXX"
    assert p.id > 0
    assert db.status(p.id) == "rascunho"
    assert p.logado(), "quem acabou de se cadastrar deveria seguir logado"


@pytest.mark.parametrize("cpf", ["12345678900", "11111111111", "123", ""])
def test_pre_cadastro_recusa_cpf_invalido(novo_participante, cpf):
    p = novo_participante(cpf=cpf)
    r = p.pre_cadastro()
    assert r.status == 422 and r.campo == "cpf"


def test_pre_cadastro_recusa_nome_curto(novo_participante):
    r = novo_participante(nome="Ab").pre_cadastro()
    assert r.status == 422 and r.campo == "nome"


def test_pre_cadastro_recusa_robo_pelo_honeypot(novo_participante, db):
    p = novo_participante()
    r = p.pre_cadastro(festa_hp="http://spam.example")
    assert r.status == 400
    assert db.id_por_nome(p.nome) is None


@pytest.mark.parametrize("nonce", ["", "abc123", "0000000000"])
def test_pre_cadastro_exige_nonce_valido(novo_participante, db, nonce):
    p = novo_participante()
    r = p.ajax("festa_auth_ini_inscricao", {"cpf": p.cpf, "nome": p.nome, "festa_hp": ""}, nonce=nonce)
    assert r.status == 403 and r.dados.get("code") == "nonce"
    assert db.id_por_nome(p.nome) is None


def test_pre_cadastro_nao_duplica_cpf(novo_participante):
    a = novo_participante()
    assert a.pre_cadastro().ok
    b = novo_participante(cpf=a.cpf)
    r = b.pre_cadastro()
    assert r.status == 409 and r.campo == "cpf"


# -- login e sessão ----------------------------------------------------------

def test_login_com_senha_errada_e_recusado(fluxo):
    p = fluxo("rascunho")
    p.logout()
    r = p.login("senha-errada")
    assert r.status == 401
    assert not p.logado()


def test_login_com_senha_certa_abre_o_painel(fluxo):
    p = fluxo("rascunho")
    p.logout()
    assert not p.logado()
    r = p.login()
    assert r.ok, r.corpo
    assert p.logado()


def test_mensagem_de_erro_nao_revela_se_cpf_existe(fluxo, novo_participante):
    existente = fluxo("rascunho")
    existente.logout()
    r1 = existente.login("senha-errada")
    r2 = novo_participante().login("senha-errada")
    assert (r1.status, r1.mensagem) == (r2.status, r2.mensagem)


def test_logout_encerra_a_sessao(fluxo):
    p = fluxo("rascunho")
    assert p.logout().ok
    assert not p.logado()


def test_cinco_erros_bloqueiam_a_inscricao(fluxo):
    p = fluxo("rascunho")
    p.logout()
    for _ in range(5):
        assert p.login("senha-errada").status == 401
    r = p.login()
    assert r.status == 401, "com 5 erros seguidos a inscrição deveria ficar bloqueada"


def test_cookie_copiado_para_outro_navegador_nao_vale(fluxo, amb, db):
    """A sessão fica presa ao navegador (User-Agent) que a abriu."""
    p = fluxo("rascunho")
    ladrao = Participante(amb["pages"], db, ua="outro-navegador/1.0")
    ladrao.http.cookies.update(p.http.cookies)

    assert not ladrao.logado(), "o cookie roubado abriu o painel em outro navegador"
    # Por desenho, o uso do cookie em outro navegador derruba a sessão original.
    assert not p.logado()


def test_cookie_forjado_nao_abre_sessao(amb, db):
    intruso = Participante(amb["pages"], db)
    intruso.http.cookies.set("festa_auth_token", "a" * 64)
    assert not intruso.logado()


# -- finalização -------------------------------------------------------------

def test_finalizacao_valida_vai_para_analise_e_gera_ficha(fluxo, db):
    p = fluxo("rascunho")
    r = p.finalizar()
    assert r.ok, r.corpo
    assert db.status(p.id) == "aguardando_analise"
    assert (db.meta(p.id, "_upload_ficha_gerada") or "").endswith(".html")
    assert db.meta(p.id, "_aceite_em"), "o aceite dos termos deveria ficar registrado"


@pytest.mark.parametrize("campo,valor", [
    ("email", "nao-e-email"),
    ("telefone", "123"),
    ("grupo", ""),
    ("qtd_participantes", "0"),
    ("qtd_participantes", "501"),
    ("coccao", "lenha"),
    ("espaco", "3"),
    ("aceite", None),
])
def test_finalizacao_recusa_campo_invalido(fluxo, db, campo, valor):
    p = fluxo("rascunho")
    r = p.finalizar(**{campo: valor})
    assert r.status == 422, r.corpo
    esperado = "aceite" if campo == "aceite" else campo
    assert r.campo == esperado
    assert db.status(p.id) == "rascunho", "dados inválidos não podem mudar o status"


def test_finalizacao_sem_token_da_sessao_e_recusada(fluxo, db):
    p = fluxo("rascunho")
    r = p.finalizar(csrf="token-forjado")
    assert r.status == 403
    assert db.status(p.id) == "rascunho"


def test_finalizacao_sem_sessao_e_recusada(fluxo, amb, db):
    p = fluxo("rascunho")
    anonimo = Participante(amb["pages"], db)
    r = anonimo.finalizar(csrf="")
    assert r.status == 401
    assert db.status(p.id) == "rascunho"


def test_finalizacao_nao_acontece_duas_vezes(fluxo):
    p = fluxo("aguardando_analise")
    r = p.finalizar()
    assert r.status == 409


def test_finalizacao_aceita_documentos_opcionais(fluxo, db):
    p = fluxo("rascunho")
    r = p.finalizar(arquivos={"identidade": pdf("rg.pdf"), "comprovante_residencia": arquivo("conta.png", PNG_OK, "image/png")})
    assert r.ok, r.corpo
    assert (db.meta(p.id, "_upload_identidade") or "").endswith(".pdf")
    assert (db.meta(p.id, "_upload_comprovante_residencia") or "").endswith(".png")


# -- envio de documentos -----------------------------------------------------

def test_envio_da_ficha_assinada_em_pdf(fluxo, db):
    p = fluxo("aguardando_analise")
    r = p.enviar_documento("ficha_assinada", pdf("ficha assinada.pdf"))
    assert r.ok, r.corpo
    nome = db.meta(p.id, "_upload_ficha_assinada") or ""
    assert nome.endswith(".pdf") and len(nome) == 40, "o arquivo deveria ser renomeado para UUID.pdf"


@pytest.mark.parametrize("arq", [
    arquivo("ficha.pdf", PHP_COMO_PDF, "application/pdf"),
    arquivo("foto.jpg", HTML_COMO_JPG, "image/jpeg"),
    arquivo("ficha.pdf", b"", "application/pdf"),
    arquivo("planilha.pdf", b"PK\x03\x04" + b"0" * 64, "application/pdf"),
], ids=["php-disfarcado-de-pdf", "html-disfarcado-de-jpg", "arquivo-vazio", "zip-disfarcado-de-pdf"])
def test_envio_recusa_arquivo_disfarcado(fluxo, db, arq):
    p = fluxo("aguardando_analise")
    r = p.enviar_documento("ficha_assinada", arq)
    assert r.status == 422 and r.campo == "documento", r.corpo
    assert db.meta(p.id, "_upload_ficha_assinada") is None


def test_envio_recusa_tipo_de_documento_desconhecido(fluxo):
    p = fluxo("aguardando_analise")
    r = p.enviar_documento("deferimento")
    assert r.status == 400, "o participante não pode enviar o deferimento da Comissão"


def test_recibo_da_tenda_so_depois_do_sorteio(fluxo, db):
    p = fluxo("aguardando_analise")
    r = p.enviar_documento("recibo_tenda")
    assert r.status == 409
    assert db.meta(p.id, "_upload_recibo_tenda") is None


def test_ficha_nao_pode_ser_trocada_depois_do_deferimento(fluxo):
    p = fluxo("deferido")
    r = p.enviar_documento("ficha_assinada")
    assert r.status == 409


# -- troca de senha ----------------------------------------------------------

def test_troca_de_senha_exige_a_senha_atual(fluxo):
    p = fluxo("rascunho")
    r = p.trocar_senha("errada", "Uma frase bem minha 2026")
    assert r.status == 422 and r.campo == "senha_atual"


@pytest.mark.parametrize("nova", ["curta", "12345678abc", "senhaforte", "aaaaaaaaaa"])
def test_troca_de_senha_recusa_senha_fraca(fluxo, nova):
    p = fluxo("rascunho")
    r = p.trocar_senha(p.senha, nova)
    assert r.status == 422 and r.campo == "senha_nova"


def test_troca_de_senha_recusa_o_proprio_cpf(fluxo):
    p = fluxo("rascunho")
    r = p.trocar_senha(p.senha, f"x{p.cpf}y")
    assert r.status == 422 and r.campo == "senha_nova"


def test_troca_de_senha_exige_confirmacao_igual(fluxo):
    p = fluxo("rascunho")
    r = p.trocar_senha(p.senha, "Uma frase bem minha 2026", "Outra frase 2026")
    assert r.status == 422


def test_troca_de_senha_valida(fluxo):
    p = fluxo("rascunho")
    antiga, nova = p.senha, "Uma frase bem minha 2026"
    assert p.trocar_senha(antiga, nova).ok

    p.logout()
    assert p.login(antiga).status == 401, "a senha antiga continuou valendo"
    assert p.login(nova).ok


def test_painel_sem_sessao_mostra_so_o_login(amb, db):
    anonimo = Participante(amb["pages"], db)
    html = anonimo.painel()
    assert "Acessar inscrição" in html
    assert "Minha inscrição" not in html
    assert soup(html).find("input", attrs={"name": "festa_csrf"}).get("value") == ""
