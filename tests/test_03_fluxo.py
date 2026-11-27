"""
Integridade do fluxo: as cinco fases na ordem certa, com cada ator fazendo a
sua parte, e nenhum atalho entre elas.
"""

from festa import achar_form, msg_do_redirect, pdf


def _subsequencia(esperado: list[str], real: list[str]) -> bool:
    it = iter(real)
    return all(any(x == y for y in it) for x in esperado)


def test_fluxo_completo_com_todos_os_atores(novo_participante, comissao, defesa, db):
    p = novo_participante()

    # Fase 1: inscrição
    assert p.pre_cadastro().ok
    assert db.status(p.id) == "rascunho"
    assert p.finalizar().ok
    assert db.status(p.id) == "aguardando_analise"
    assert p.enviar_documento("ficha_assinada", pdf("ficha-assinada.pdf")).ok

    # Fase 2: análise
    assert msg_do_redirect(comissao.deferir(p.id)) == "deferido"
    assert db.status(p.id) == "deferido"
    assert db.meta(p.id, "_upload_deferimento")

    # Fase 3: sorteio
    assert msg_do_redirect(comissao.tenda(p.id, "C-03")) == "tenda"
    assert db.status(p.id) == "aguardando_comprovantes"
    assert db.meta(p.id, "_numero_tenda") == "C-03"
    assert "C-03" in p.painel()

    # Fase 4: validação dupla
    assert p.enviar_documento("recibo_tenda").ok
    assert msg_do_redirect(comissao.aprovar_recibo(p.id)) == "recibo"
    assert db.status(p.id) == "aguardando_comprovantes", "só o recibo não pode concluir"
    defesa.confirmar_colchoes(p.cpf)

    # Fase 5: conclusão
    assert db.status(p.id) == "concluido"
    assert db.meta(p.id, "_upload_comprovante_doacao")
    assert db.meta(p.id, "_upload_termo_cessao"), "o Termo de Cessão deveria ser gerado na conclusão"

    painel = p.painel()
    assert "Inscrição concluída" in painel
    termos = [l for l in p.links_documentos() if "_upload_termo_cessao" in l]
    assert termos and p.http.get(termos[0]).status_code == 200

    assert _subsequencia(
        ["pre_cadastro", "status_transition", "status_transition", "tenda", "status_transition",
         "recibo_aprovado", "colchoes_confirmados", "status_transition"],
        db.auditoria(p.id),
    ), db.auditoria(p.id)


def test_conclusao_independe_da_ordem_das_validacoes(fluxo, comissao, defesa, db):
    """Defesa Civil confirma antes; a aprovação do recibo é que conclui."""
    p = fluxo("recibo_enviado")
    defesa.confirmar_colchoes(p.cpf)
    assert db.status(p.id) == "aguardando_comprovantes"
    assert msg_do_redirect(comissao.aprovar_recibo(p.id)) == "concluido"
    assert db.status(p.id) == "concluido"


def test_so_os_colchoes_nao_concluem(fluxo, defesa, db):
    p = fluxo("aguardando_comprovantes")
    defesa.confirmar_colchoes(p.cpf)
    assert db.meta(p.id, "_colchoes_confirmados") == "1"
    assert db.status(p.id) == "aguardando_comprovantes"


def test_recibo_nao_e_aprovado_antes_de_ser_enviado(fluxo, comissao, db):
    p = fluxo("aguardando_comprovantes")
    assert not comissao.tem_form(p.id, "festa_recibo"), "a tela ofereceu aprovar um recibo que não existe"
    antes = db.retrato(p.id)

    nonce = comissao.nonces([f"festa_recibo_{p.id}"])[f"festa_recibo_{p.id}"]
    r = comissao.admin_post("festa_recibo", {"id": p.id, "_wpnonce": nonce})
    assert msg_do_redirect(r) == "status_erro"
    assert db.retrato(p.id) == antes


def test_formulario_antigo_nao_refaz_etapa(fluxo, comissao, db):
    """Reenviar o formulário de deferimento (aba antiga) não volta o fluxo."""
    p = fluxo("aguardando_analise")
    html_antigo = comissao.tela_inscricao(p.id)
    assert msg_do_redirect(comissao.deferir(p.id)) == "deferido"
    assert msg_do_redirect(comissao.tenda(p.id)) == "tenda"

    r = comissao.enviar_form(html_antigo, "festa_deferir", comissao.url_inscricao(p.id),
                             arquivos={"documento": pdf()})
    assert msg_do_redirect(r) == "status_erro"
    assert db.status(p.id) == "aguardando_comprovantes"


def test_tenda_nao_pode_ser_sorteada_antes_do_deferimento(fluxo, comissao, db):
    p = fluxo("aguardando_analise")
    assert not comissao.tem_form(p.id, "festa_tenda")
    nonce = comissao.nonces([f"festa_tenda_{p.id}"])[f"festa_tenda_{p.id}"]
    r = comissao.admin_post("festa_tenda", {"id": p.id, "_wpnonce": nonce, "numero_tenda": "Z-99"})
    assert msg_do_redirect(r) == "status_erro"
    assert db.status(p.id) == "aguardando_analise"
    assert db.meta(p.id, "_numero_tenda") is None


def test_deferimento_exige_o_documento(fluxo, comissao, db):
    p = fluxo("aguardando_analise")
    r = comissao.acao(p.id, "festa_deferir")  # sem arquivo
    assert msg_do_redirect(r) == "sem_arquivo"
    assert db.status(p.id) == "aguardando_analise"


def test_deferimento_recusa_arquivo_disfarcado(fluxo, comissao, db):
    p = fluxo("aguardando_analise")
    r = comissao.deferir(p.id, ("deferimento.pdf", b"<?php echo 1; ?>", "application/pdf"))
    assert msg_do_redirect(r) == "erro"
    assert db.status(p.id) == "aguardando_analise"


def test_correcao_do_numero_da_tenda_nao_muda_o_status(fluxo, comissao, db):
    p = fluxo("aguardando_comprovantes")
    assert msg_do_redirect(comissao.tenda(p.id, "D-44")) == "tenda_fix"
    assert db.meta(p.id, "_numero_tenda") == "D-44"
    assert db.status(p.id) == "aguardando_comprovantes"


def test_defesa_so_ve_botao_quando_a_inscricao_e_elegivel(fluxo, defesa):
    p = fluxo("deferido")
    html = defesa.buscar_cpf(p.cpf)
    assert "não está elegível" in html
    assert achar_form(html, "festa_colchoes") is None


def test_colchoes_nao_sao_confirmados_duas_vezes(fluxo, defesa, db):
    p = fluxo("aguardando_comprovantes")
    html = defesa.buscar_cpf(p.cpf)
    defesa.enviar_form(html, "festa_colchoes", defesa.url_colchoes())
    n = db.auditoria(p.id).count("colchoes_confirmados")

    defesa.enviar_form(html, "festa_colchoes", defesa.url_colchoes())  # mesmo formulário de novo
    assert db.auditoria(p.id).count("colchoes_confirmados") == n


def test_inscricao_concluida_fica_somente_leitura(fluxo, comissao, defesa, db):
    p = fluxo("recibo_aprovado")
    html_antigo = comissao.tela_inscricao(p.id)
    defesa.confirmar_colchoes(p.cpf)
    assert db.status(p.id) == "concluido"
    antes = db.retrato(p.id)

    r = comissao.enviar_form(html_antigo, "festa_contato", comissao.url_inscricao(p.id),
                             {"email": "novo@example.test", "telefone": "(21) 99999-0000"})
    assert msg_do_redirect(r) == "status_erro"
    assert db.retrato(p.id) == antes
