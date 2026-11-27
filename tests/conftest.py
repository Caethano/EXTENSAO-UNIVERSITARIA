"""
Fixtures da suíte.

O ambiente é preparado uma vez por execução (usuários de teste, portal ligado,
inscrições abertas) e desmontado no final. Para inspecionar os dados depois de
uma execução, rode com FESTA_MANTER=1.
"""

import os

import pytest

from festa import Db, EquipePortal, EquipeWP, Participante, ambiente, msg_do_redirect


@pytest.fixture(scope="session")
def amb():
    cfg = ambiente("preparar")
    yield cfg
    if not os.environ.get("FESTA_MANTER"):
        ambiente("desmontar")


@pytest.fixture(scope="session")
def db(amb) -> Db:
    return Db(amb["prefix"])


@pytest.fixture
def limites_zerados(db):
    """
    Zera bloqueios e limites de taxa (todos os testes saem do mesmo IP).
    Entra pelos fixtures que criam participantes ou fazem login.
    """
    db.zerar_limites()


# -- atores do wp-admin ------------------------------------------------------

def _wp(amb, papel: str) -> EquipeWP:
    u = amb["wp"][papel]
    return EquipeWP(u["login"], u["senha"], u["id"])


@pytest.fixture(scope="session")
def admin(amb) -> EquipeWP:
    return _wp(amb, "admin")


@pytest.fixture(scope="session")
def comissao(amb) -> EquipeWP:
    return _wp(amb, "comissao")


@pytest.fixture(scope="session")
def defesa(amb) -> EquipeWP:
    return _wp(amb, "defesa")


@pytest.fixture(scope="session")
def assinante(amb) -> EquipeWP:
    return _wp(amb, "assinante")


# -- atores do portal --------------------------------------------------------

@pytest.fixture
def portal(amb, limites_zerados):
    """portal('comissao' | 'defesa' | 'leitor' | 'novo') → conta logada."""
    def _entrar(papel: str) -> EquipePortal:
        u = amb["portal"][papel]
        conta = EquipePortal(amb["portal_url"], u["login"], u["senha"])
        assert conta.ultima is not None and conta.ultima.status_code == 303, \
            f"login no portal falhou para {u['login']}: {conta.ultima.status_code if conta.ultima else '-'}"
        return conta
    return _entrar


# -- participantes e fluxo ---------------------------------------------------

@pytest.fixture
def novo_participante(amb, db, limites_zerados):
    def _novo(**kw) -> Participante:
        return Participante(amb["pages"], db, **kw)
    return _novo


ETAPAS = [
    "rascunho",
    "aguardando_analise",
    "deferido",
    "aguardando_comprovantes",
    "recibo_enviado",
    "recibo_aprovado",
    "concluido",
]


@pytest.fixture
def fluxo(novo_participante, comissao, defesa, db):
    """
    Leva uma inscrição nova até a etapa pedida, sempre pelos caminhos reais
    (participante pelo front-end, Comissão e Defesa Civil pelo wp-admin).
    """
    def _ate(etapa: str) -> Participante:
        assert etapa in ETAPAS, etapa
        alvo = ETAPAS.index(etapa)
        p = novo_participante()

        r = p.pre_cadastro()
        assert r.ok, r.corpo
        if alvo == 0:
            return p

        r = p.finalizar()
        assert r.ok, r.corpo
        if alvo == 1:
            return p

        assert msg_do_redirect(comissao.deferir(p.id)) == "deferido"
        if alvo == 2:
            return p

        assert msg_do_redirect(comissao.tenda(p.id, "B-7")) == "tenda"
        if alvo == 3:
            return p

        r = p.enviar_documento("recibo_tenda")
        assert r.ok, r.corpo
        if alvo == 4:
            return p

        assert msg_do_redirect(comissao.aprovar_recibo(p.id)) == "recibo"
        if alvo == 5:
            return p

        defesa.confirmar_colchoes(p.cpf)
        assert db.status(p.id) == "concluido"
        return p

    return _ate
