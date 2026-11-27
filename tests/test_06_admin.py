"""
Administrador e ferramentas da equipe: configurações, contas do portal,
exclusão, nova senha, exportações e anotações internas.
"""

import io
import uuid
import zipfile
from urllib.parse import urljoin

import pytest

from festa import ADMIN, EquipePortal, achar_form, campos_do_form, compose, msg_do_redirect, pdf, soup


def _salvar_config(admin, **mudancas) -> None:
    url = f"{ADMIN}admin.php?page=festa-configuracoes"
    form = achar_form(admin.http.get(url).text, "festa_save_settings")
    dados = campos_do_form(form)
    for k, v in mudancas.items():
        if v is None:
            dados.pop(k, None)
        else:
            dados[k] = v
    r = admin.http.post(urljoin(url, form.get("action")), data=dados, allow_redirects=False)
    assert r.status_code == 302 and "saved=1" in r.headers["Location"], r.status_code


def _flash_senha(html: str) -> str | None:
    el = soup(html).select_one("code.festa-flash-pass")
    return el.get_text(strip=True) if el else None


# -- configurações -------------------------------------------------------------

def test_admin_fecha_e_reabre_as_inscricoes(admin, fluxo, novo_participante):
    rascunho = fluxo("rascunho")
    _salvar_config(admin, aberto=None)
    try:
        r = novo_participante().pre_cadastro()
        assert r.status == 403 and "encerradas" in r.mensagem
        r = rascunho.finalizar()
        assert r.status == 403, "a finalização deveria respeitar o encerramento"
    finally:
        _salvar_config(admin, aberto="1")
    assert novo_participante().pre_cadastro().ok


def test_prazo_vencido_encerra_as_inscricoes(admin, novo_participante):
    _salvar_config(admin, encerra_em="2020-01-01T00:00")
    try:
        r = novo_participante().pre_cadastro()
        assert r.status == 403
    finally:
        _salvar_config(admin, encerra_em="")


# -- contas do portal ------------------------------------------------------------

def test_admin_cria_conta_no_portal_com_senha_mostrada_uma_vez(admin, amb, limites_zerados):
    url = f"{ADMIN}admin.php?page=festa-portal"
    login = f"festa-t-criada-{uuid.uuid4().hex[:6]}"
    form = achar_form(admin.http.get(url).text, "festa_portal_user_create")
    dados = campos_do_form(form)
    dados.update({"nome": "Conta Criada Pelo Teste", "login": login, "email": f"{login}@example.test", "perms[]": ["ver"]})
    r = admin.http.post(urljoin(url, form.get("action")), data=dados, allow_redirects=False)
    assert r.status_code == 302

    senha = _flash_senha(admin.http.get(r.headers["Location"]).text)
    assert senha and len(senha) >= 12, "a senha provisória deveria aparecer logo após criar a conta"
    assert _flash_senha(admin.http.get(r.headers["Location"]).text) is None, "a senha apareceu duas vezes"

    conta = EquipePortal(amb["portal_url"], login, senha)
    assert conta.ultima.status_code == 303 and "tela=conta" in conta.ultima.headers["Location"]


# -- ações sobre a inscrição ---------------------------------------------------

def test_admin_exclui_inscricao_com_os_documentos(admin, fluxo, db, amb):
    p = fluxo("aguardando_analise")
    pasta = f"{amb['uploads_dir']}/{p.id}"
    assert compose("exec", "-T", "wordpress", "test", "-d", pasta).returncode == 0

    r = admin.acao(p.id, "festa_delete")
    assert msg_do_redirect(r) == "deleted"
    assert not db.existe(p.id)
    assert compose("exec", "-T", "wordpress", "test", "-d", pasta).returncode != 0, "os documentos ficaram no disco"
    assert "exclusao" in db.auditoria(p.id), "a exclusão deveria ficar no histórico"


def test_nova_senha_do_participante(comissao, fluxo):
    p = fluxo("aguardando_analise")
    antiga = p.senha

    r = comissao.nova_senha(p.id)
    assert r.status_code == 302
    senha = _flash_senha(comissao.http.get(r.headers["Location"]).text)
    assert senha and senha != antiga
    assert _flash_senha(comissao.http.get(r.headers["Location"]).text) is None

    p.logout()
    assert p.login(antiga).status == 401
    assert p.login(senha).ok


def test_nova_senha_destrava_inscricao_bloqueada(comissao, fluxo):
    p = fluxo("aguardando_analise")
    p.logout()
    for _ in range(5):
        p.login("senha-errada")
    r = comissao.nova_senha(p.id)
    senha = _flash_senha(comissao.http.get(r.headers["Location"]).text)
    assert p.login(senha).ok


def test_observacoes_internas_nao_chegam_ao_participante(comissao, fluxo, db):
    p = fluxo("aguardando_analise")
    texto = f"nota interna {uuid.uuid4().hex[:8]}"
    assert msg_do_redirect(comissao.observacoes(p.id, texto)) == "notes"
    assert db.meta(p.id, "_observacoes") == texto
    assert texto not in p.painel()


def test_correcao_de_contato(comissao, fluxo, db):
    p = fluxo("aguardando_analise")
    assert msg_do_redirect(comissao.contato(p.id, "invalido", "123")) == "contato_erro"
    assert msg_do_redirect(comissao.contato(p.id, "novo@example.test", "(21) 99876-5432")) == "contato"
    assert db.meta(p.id, "_email") == "novo@example.test"
    assert "contato" in db.auditoria(p.id)


def test_equipe_anexa_documento_entregue_em_maos(comissao, fluxo, db):
    p = fluxo("aguardando_analise")
    assert msg_do_redirect(comissao.anexar(p.id, "identidade", pdf("rg.pdf"))) == "anexado"
    assert (db.meta(p.id, "_upload_identidade") or "").endswith(".pdf")


# -- exportações -----------------------------------------------------------------

def _link(equipe, trecho: str) -> str:
    html = equipe.http.get(equipe.url_lista()).text
    for a in soup(html).find_all("a", href=True):
        if trecho in a["href"]:
            return a["href"]
    raise AssertionError(f"link com '{trecho}' não encontrado na lista")


def test_csv_mascara_o_cpf_e_neutraliza_formulas(comissao, fluxo):
    p = fluxo("rascunho")
    assert p.finalizar(grupo='=HYPERLINK("http://x.example","clique")').ok

    r = comissao.http.get(_link(comissao, "action=festa_export&"))
    assert r.status_code == 200 and r.headers["Content-Type"].startswith("text/csv")
    csv = r.content.decode("utf-8-sig")

    assert p.nome in csv
    assert p.cpf not in csv, "CPF completo na exportação"
    assert f"{p.cpf[3:6]}.{p.cpf[6:9]}" in csv
    assert "'=HYPERLINK" in csv, "fórmula do Excel não foi neutralizada"


def test_pdf_da_lista_nao_tem_cpf_completo(comissao, fluxo):
    p = fluxo("aguardando_analise")
    r = comissao.http.get(_link(comissao, "action=festa_export_pdf"))
    assert r.status_code == 200 and r.content.startswith(b"%PDF")
    assert p.cpf.encode() not in r.content


def test_zip_traz_os_documentos_da_inscricao(comissao, fluxo):
    p = fluxo("aguardando_analise")
    assert p.enviar_documento("ficha_assinada").ok
    html = comissao.tela_inscricao(p.id)
    href = next(a["href"] for a in soup(html).find_all("a", href=True) if "festa_zip" in a["href"])
    r = comissao.http.get(href)
    assert r.status_code == 200
    nomes = zipfile.ZipFile(io.BytesIO(r.content)).namelist()
    assert len(nomes) == 2 and all(n.startswith(f"{p.id:04d}-") for n in nomes), nomes
    assert any(n.endswith("assinada.pdf") for n in nomes) and any(n.endswith(".html") for n in nomes)


def test_auditoria_registra_quem_fez(comissao, fluxo, db, amb):
    p = fluxo("deferido")
    uid = db.scalar(
        f"SELECT user_id FROM {db.p}festa_audit_log WHERE inscricao_id={p.id} AND action='documento' ORDER BY id DESC LIMIT 1"
    )
    assert uid == str(amb["wp"]["comissao"]["id"])


@pytest.mark.parametrize("papel", ["admin", "comissao"])
def test_tela_de_auditoria_abre_para_quem_ve(request, papel, fluxo):
    p = fluxo("aguardando_analise")
    r = request.getfixturevalue(papel).pagina("festa_audit_log")
    assert r.status_code == 200
    assert f"view={p.id}" in r.text, "a entrada da inscrição deveria aparecer com link para ela"
