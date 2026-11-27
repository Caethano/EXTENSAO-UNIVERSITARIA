"""
Integridade dos dados guardados (LGPD): o que vai para o banco e para o disco.
"""

import re

from festa import compose, pdf, q


def _ocorrencias(db, texto: str) -> dict[str, int]:
    """Em quantos lugares do banco o texto aparece em claro."""
    p, like = db.p, q(f"%{texto}%")
    sql = (
        f"SELECT 'postmeta', COUNT(*) FROM {p}postmeta WHERE meta_value LIKE {like} "
        f"UNION ALL SELECT 'posts', COUNT(*) FROM {p}posts WHERE CONCAT(post_title, post_content, post_excerpt, post_name) LIKE {like} "
        f"UNION ALL SELECT 'options', COUNT(*) FROM {p}options WHERE option_value LIKE {like} "
        f"UNION ALL SELECT 'audit', COUNT(*) FROM {p}festa_audit_log WHERE CONCAT(detail, old_value, new_value, actor) LIKE {like} "
        f"UNION ALL SELECT 'portal_log', COUNT(*) FROM {p}festa_portal_log WHERE detalhe LIKE {like}"
    )
    return {k: int(v) for k, v in db.query(sql) if int(v) > 0}


def test_cpf_nunca_fica_em_texto_puro(fluxo, db):
    p = fluxo("concluido")
    formatado = f"{p.cpf[:3]}.{p.cpf[3:6]}.{p.cpf[6:9]}-{p.cpf[9:]}"
    assert _ocorrencias(db, p.cpf) == {}
    assert _ocorrencias(db, formatado) == {}


def test_cpf_e_guardado_cifrado_e_com_hash_de_busca(fluxo, db):
    p = fluxo("rascunho")
    cifrado = db.meta(p.id, "_cpf") or ""
    assert re.fullmatch(r"[A-Za-z0-9+/=]{24}:[A-Za-z0-9+/=]+", cifrado), "formato esperado base64(IV):base64(texto cifrado)"
    assert re.fullmatch(r"[a-f0-9]{64}", db.meta(p.id, "_cpf_hash") or "")


def test_mesmo_texto_cifrado_nunca_se_repete(fluxo, db):
    """IV aleatório: duas inscrições nunca têm o mesmo começo de texto cifrado."""
    a, b = fluxo("rascunho"), fluxo("rascunho")
    assert db.meta(a.id, "_cpf").split(":")[0] != db.meta(b.id, "_cpf").split(":")[0]


def test_senha_do_participante_nunca_fica_em_texto_puro(fluxo, db):
    p = fluxo("aguardando_analise")
    assert _ocorrencias(db, p.senha) == {}
    assert (db.meta(p.id, "_senha_hash") or "").startswith("$"), "a senha deveria estar guardada como hash"


def test_senha_trocada_tambem_nao_fica_em_texto_puro(fluxo, db):
    p = fluxo("rascunho")
    nova = "Frase secreta do grupo 2026"
    assert p.trocar_senha(p.senha, nova).ok
    assert _ocorrencias(db, nova) == {}


def test_documento_gerado_escapa_os_dados_digitados(fluxo):
    p = fluxo("rascunho")
    assert p.finalizar(grupo='Tom & "Jerry" <b>negrito</b>').ok
    ficha = p.http.get(p.links_documentos()[0]).text
    assert "Tom &amp; &quot;Jerry&quot;" in ficha
    assert "<b>" not in ficha


def test_nome_original_do_arquivo_e_higienizado(fluxo, db):
    p = fluxo("aguardando_analise")
    assert p.enviar_documento("ficha_assinada", pdf('../../etc/passwd<script>"x".pdf')).ok
    nome_salvo = db.meta(p.id, "_upload_ficha_assinada")
    assert re.fullmatch(r"[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\.pdf", nome_salvo)
    info = db.meta(p.id, "_upload_ficha_assinada_info") or ""
    assert "../" not in info and "<script>" not in info and "passwd" in info


def test_arquivos_em_disco_nao_sao_legiveis_por_outros(fluxo, db, amb):
    p = fluxo("aguardando_analise")
    assert p.enviar_documento("ficha_assinada").ok
    caminho = f"{amb['uploads_dir']}/{p.id}/{db.meta(p.id, '_upload_ficha_assinada')}"
    modo = compose("exec", "-T", "wordpress", "stat", "-c", "%a", caminho).stdout.strip()
    assert modo == "640", f"permissão {modo} no arquivo enviado"


def test_ip_do_participante_e_guardado_anonimizado(fluxo, db):
    p = fluxo("rascunho")
    ip = db.meta(p.id, "_ip_inscricao") or ""
    assert ip.endswith(".0") or ip.endswith("::"), f"IP guardado sem anonimizar: {ip}"


def test_historico_de_status_e_completo(fluxo, db):
    p = fluxo("concluido")
    transicoes = db.query(
        f"SELECT old_value, new_value FROM {db.p}festa_audit_log "
        f"WHERE inscricao_id={p.id} AND action='status_transition' ORDER BY id"
    )
    assert [tuple(t) for t in transicoes] == [
        ("rascunho", "aguardando_analise"),
        ("aguardando_analise", "deferido"),
        ("deferido", "aguardando_comprovantes"),
        ("aguardando_comprovantes", "concluido"),
    ]
