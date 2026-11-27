"""
Isolamento: um participante nunca alcança a inscrição ou os documentos de
outro, e ninguém de fora abre os documentos guardados.
"""

from urllib.parse import parse_qs, urlencode, urlparse, urlunparse

import pytest
import requests

from festa import AJAX, Participante, pdf, url_da_pasta_privada


def _trocar_param(url: str, **novos) -> str:
    partes = urlparse(url)
    qs = {k: v[0] for k, v in parse_qs(partes.query).items()}
    qs.update({k: str(v) for k, v in novos.items()})
    return urlunparse(partes._replace(query=urlencode(qs)))


@pytest.fixture
def dois(fluxo):
    """Dois participantes com a inscrição finalizada (ficha gerada)."""
    return fluxo("aguardando_analise"), fluxo("aguardando_analise")


def test_participante_nao_envia_documento_para_inscricao_alheia(dois, db):
    a, b = dois
    r = b.enviar_documento("ficha_assinada", pdf(), inscricao_id=a.id)
    assert r.status == 403
    assert db.meta(a.id, "_upload_ficha_assinada") is None


def test_participante_so_ve_a_propria_inscricao_no_painel(dois):
    a, b = dois
    html = b.painel()
    assert f"#{b.id}" in html
    assert f"#{a.id}" not in html
    assert a.nome not in html


def test_link_assinado_abre_o_proprio_documento(dois):
    a, _ = dois
    links = a.links_documentos()
    assert links, "o painel deveria oferecer a ficha de inscrição"
    r = a.http.get(links[0])
    assert r.status_code == 200
    assert r.headers["Content-Type"].startswith("text/html")
    assert "default-src 'none'" in r.headers.get("Content-Security-Policy", "")
    assert r.headers.get("X-Content-Type-Options") == "nosniff"
    assert a.nome in r.text


def test_link_assinado_nao_serve_para_outro_participante(dois):
    a, b = dois
    link = a.links_documentos()[0]
    r = b.http.get(link)
    assert r.status_code == 403
    assert a.nome not in r.text


def test_link_assinado_nao_serve_para_visitante_anonimo(dois):
    a, _ = dois
    link = a.links_documentos()[0]
    r = requests.get(link)
    assert r.status_code == 403


@pytest.mark.parametrize("campo,valor", [
    ("id", "OUTRO"),
    ("key", "_upload_identidade"),
    ("expires", "9999999999"),
    ("signature", "0" * 64),
])
def test_link_adulterado_e_recusado(dois, campo, valor):
    a, b = dois
    link = a.links_documentos()[0]
    adulterado = _trocar_param(link, **{campo: b.id if valor == "OUTRO" else valor})
    r = a.http.get(adulterado)
    assert r.status_code == 403


def test_link_sem_assinatura_e_recusado(dois):
    a, _ = dois
    r = a.http.get(AJAX, params={"action": "festa_download_secure", "id": a.id, "key": "_upload_ficha_gerada"})
    assert r.status_code == 403


def test_pasta_privada_nao_e_acessivel_direto(dois, db, amb):
    a, _ = dois
    arquivo = db.meta(a.id, "_upload_ficha_gerada")
    base = url_da_pasta_privada(amb)
    if base is None:
        pytest.skip("a pasta privada fica fora da raiz do site: não existe URL para ela")

    assert requests.get(f"{base}/").status_code == 403
    assert requests.get(f"{base}/{a.id}/").status_code == 403
    assert requests.get(f"{base}/{a.id}/{arquivo}").status_code == 403


def test_sessao_de_um_participante_nao_vale_na_inscricao_do_outro(dois, db):
    """Mesmo com o token CSRF do outro, a sessão define a inscrição."""
    a, b = dois
    r = b.ajax(
        "festa_upload_documentos",
        {"document_type": "ficha_assinada", "inscricao_id": str(a.id)},
        arquivos={"documento": pdf()},
        csrf=a.csrf(),
    )
    assert r.status == 403
    assert db.meta(a.id, "_upload_ficha_assinada") is None
