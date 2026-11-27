"""
Biblioteca da suíte: emula os atores do plugin Festa dos Amigos 2026 via HTTP.

- Participante: pré-cadastro, login por CPF + senha, finalização, envios.
- Equipe (wp-admin): Comissão, Defesa Civil, Administrador, Assinante.
- Equipe (portal externo): contas com login próprio.
- Atacante em outra rede: requisições feitas de dentro do container
  (REMOTE_ADDR diferente do seu), para separar "outra pessoa" de "você".

Toda verificação de estado lê o banco direto (Db), sem depender da tela.
"""

from __future__ import annotations

import json
import os
import random
import re
import socket
import subprocess
import uuid
from dataclasses import dataclass, field
from typing import ClassVar
from pathlib import Path
from urllib.parse import parse_qs, unquote, urljoin, urlparse

import requests
from bs4 import BeautifulSoup

ROOT = Path(__file__).resolve().parent.parent
FIXTURES = Path(__file__).resolve().parent / "fixtures"

# O WordPress só escuta em 127.0.0.1. No Windows, "localhost" tenta antes o
# ::1 e cada conexão perde ~2s esperando a recusa: IPv4 primeiro.
_getaddrinfo = socket.getaddrinfo


def _ipv4_primeiro(host, *args, **kwargs):
    res = _getaddrinfo(host, *args, **kwargs)
    return sorted(res, key=lambda r: r[0] != socket.AF_INET) if host == "localhost" else res


socket.getaddrinfo = _ipv4_primeiro


def _read_env() -> dict[str, str]:
    env = {}
    path = ROOT / ".env"
    if path.exists():
        for line in path.read_text(encoding="utf-8").splitlines():
            if "=" in line and not line.lstrip().startswith("#"):
                k, v = line.split("=", 1)
                env[k.strip()] = v.strip()
    return env


def _config(nome: str, padrao: str) -> str:
    """Variável de ambiente, senão o .env, senão o padrão do docker-compose.yml."""
    return os.environ.get(nome) or ENV.get(nome) or padrao


ENV = _read_env()
BASE = _config("WP_URL", f"http://localhost:{_config('WP_PORT', '8080')}").rstrip("/")
AJAX = BASE + "/wp-admin/admin-ajax.php"
ADMIN = BASE + "/wp-admin/"
ADMIN_POST = BASE + "/wp-admin/admin-post.php"
MAILPIT = _config("MAIL_URL", f"http://localhost:{_config('MAIL_PORT', '8025')}").rstrip("/")

NOME_PREFIX = "Teste Auto"


# ---------------------------------------------------------------------------
# Docker / WP-CLI / banco
# ---------------------------------------------------------------------------

def compose(*args: str, stdin: str | None = None, timeout: int = 180) -> subprocess.CompletedProcess:
    return subprocess.run(
        ["docker", "compose", *args],
        cwd=ROOT,
        input=stdin,
        capture_output=True,
        text=True,
        encoding="utf-8",
        timeout=timeout,
    )


def wp_eval(php: str, *args: str) -> subprocess.CompletedProcess:
    """Roda um script PHP no WP-CLI do container do WordPress, como www-data."""
    return compose("exec", "-T", "-u", "www-data", "wordpress", "wp", "eval-file", "-", *args, stdin=php)


def ambiente(modo: str) -> dict:
    """Roda tests/fixtures/ambiente.php no WP-CLI e devolve o JSON impresso."""
    php = (FIXTURES / "ambiente.php").read_text(encoding="utf-8")
    proc = wp_eval(php, modo)
    marker = "@@FESTA_JSON@@"
    if marker not in proc.stdout:
        raise RuntimeError(f"ambiente.php {modo} falhou:\n{proc.stdout}\n{proc.stderr}")
    return json.loads(proc.stdout.split(marker, 1)[1].strip())


def q(value) -> str:
    """Literal SQL seguro para os valores que os testes montam."""
    if isinstance(value, (int, float)):
        return str(value)
    return "'" + str(value).replace("\\", "\\\\").replace("'", "''") + "'"


class Db:
    def __init__(self, prefix: str = "wp_"):
        self.p = prefix

    def query(self, sql: str) -> list[list[str]]:
        # As credenciais são as do próprio container do banco.
        proc = compose(
            "exec", "-T", "db", "sh", "-c",
            'exec mariadb -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE" -N -B',
            stdin=sql,
        )
        if proc.returncode != 0:
            raise RuntimeError(f"SQL falhou: {sql}\n{proc.stderr}")
        return [line.split("\t") for line in proc.stdout.splitlines() if line != ""]

    def scalar(self, sql: str) -> str | None:
        rows = self.query(sql)
        return rows[0][0] if rows else None

    def meta(self, post_id: int, key: str) -> str | None:
        return self.scalar(
            f"SELECT meta_value FROM {self.p}postmeta WHERE post_id={int(post_id)} AND meta_key={q(key)} LIMIT 1"
        )

    def set_meta(self, post_id: int, key: str, value: str) -> None:
        self.query(
            f"UPDATE {self.p}postmeta SET meta_value={q(value)} WHERE post_id={int(post_id)} AND meta_key={q(key)}"
        )

    def delete_meta(self, post_id: int, key: str) -> None:
        self.query(f"DELETE FROM {self.p}postmeta WHERE post_id={int(post_id)} AND meta_key={q(key)}")

    def status(self, post_id: int) -> str | None:
        return self.meta(post_id, "_status")

    def existe(self, post_id: int) -> bool:
        return self.scalar(f"SELECT COUNT(*) FROM {self.p}posts WHERE ID={int(post_id)}") == "1"

    def id_por_nome(self, nome: str) -> int | None:
        v = self.scalar(
            f"SELECT post_id FROM {self.p}postmeta WHERE meta_key='_nome_responsavel' AND meta_value={q(nome)} LIMIT 1"
        )
        return int(v) if v else None

    def retrato(self, post_id: int) -> dict[str, str]:
        """
        Estado relevante da inscrição e das configurações globais, numa
        consulta só, para comparar antes/depois de uma tentativa.
        """
        p, i = self.p, int(post_id)
        rows = self.query(
            f"SELECT meta_key, meta_value FROM {p}postmeta WHERE post_id={i} "
            "AND meta_key IN ('_status','_numero_tenda','_tenda_aprovada','_colchoes_confirmados',"
            "'_observacoes','_email','_telefone','_senha_hash','_upload_deferimento','_upload_recibo_tenda',"
            "'_upload_identidade') "
            f"UNION ALL SELECT '@existe', COUNT(*) FROM {p}posts WHERE ID={i} "
            f"UNION ALL SELECT '@settings', MD5(option_value) FROM {p}options WHERE option_name='festa_settings' "
            f"UNION ALL SELECT '@portal', MD5(option_value) FROM {p}options WHERE option_name='festa_portal' "
            f"UNION ALL SELECT '@contas_portal', COUNT(*) FROM {p}festa_portal_users"
        )
        return {r[0]: (r[1] if len(r) > 1 else "") for r in rows}

    def auditoria(self, post_id: int) -> list[str]:
        return [r[0] for r in self.query(
            f"SELECT action FROM {self.p}festa_audit_log WHERE inscricao_id={int(post_id)} ORDER BY id"
        )]

    def transicoes(self, post_id: int) -> list[tuple[str, str, str]]:
        """(de, para, detalhe) de cada transição de status registrada na auditoria."""
        return [(r[0], r[1], r[2] if len(r) > 2 else "") for r in self.query(
            f"SELECT old_value, new_value, detail FROM {self.p}festa_audit_log "
            f"WHERE inscricao_id={int(post_id)} AND action='status_transition' ORDER BY id"
        )]

    def contagem_por_status(self) -> dict[str, int]:
        """Inscrições por status, contadas direto no banco."""
        p = self.p
        rows = self.query(
            f"SELECT st.meta_value, COUNT(*) FROM {p}posts po "
            f"JOIN {p}postmeta st ON st.post_id = po.ID AND st.meta_key = '_status' "
            "WHERE po.post_type = 'inscricao_festa' AND po.post_status = 'publish' GROUP BY st.meta_value"
        )
        return {r[0]: int(r[1]) for r in rows}

    def ocupadas(self) -> int:
        """Vagas ocupadas: deferido + aguardando_comprovantes + concluido."""
        c = self.contagem_por_status()
        return sum(c.get(s, 0) for s in ("deferido", "aguardando_comprovantes", "concluido"))

    def empurrar_fila_para_o_fim(self) -> None:
        """
        Põe no fim da fila quem já estava na lista de espera (de outros testes),
        para que a ordem que um teste monta não dependa do que sobrou antes.
        """
        p = self.p
        self.query(
            f"UPDATE {p}postmeta f JOIN {p}postmeta st ON st.post_id = f.post_id AND st.meta_key = '_status' "
            "SET f.meta_value = '2099-12-31 23:59:59' "
            "WHERE f.meta_key = '_fila_em' AND st.meta_value = 'lista_espera'"
        )

    def zerar_limites(self) -> None:
        """Zera contadores de tentativas e limites de taxa (entre testes)."""
        self.query(
            f"DELETE FROM {self.p}options WHERE option_name REGEXP "
            "'^_transient_(timeout_)?festa_(rl|fail|pf)_';\n"
            f"DELETE FROM {self.p}festa_counters;\n"
            f"UPDATE {self.p}festa_portal_users SET failed_count=0, locked_until=0 WHERE login LIKE 'festa-t-%';"
        )


# ---------------------------------------------------------------------------
# Arquivos de exemplo
# ---------------------------------------------------------------------------

PDF_OK = b"%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n"
PNG_OK = bytes.fromhex(
    "89504e470d0a1a0a0000000d4948445200000001000000010806000000"
    "1f15c4890000000d49444154789c6300010000050001"
    "0d0a2db40000000049454e44ae426082"
)
PHP_COMO_PDF = b"<?php system($_GET['c']); ?>"
HTML_COMO_JPG = b"<html><body><script>alert(document.cookie)</script></body></html>"


def arquivo(nome: str, conteudo: bytes, mime: str = "application/octet-stream"):
    return (nome, conteudo, mime)


def pdf(nome: str = "documento.pdf"):
    return arquivo(nome, PDF_OK, "application/pdf")


# ---------------------------------------------------------------------------
# Utilidades
# ---------------------------------------------------------------------------

def gerar_cpf() -> str:
    base = [random.randint(0, 9) for _ in range(9)]
    if len(set(base)) == 1:
        base[0] = (base[0] + 1) % 10
    for t in (9, 10):
        s = sum(d * ((t + 1) - i) for i, d in enumerate(base[:t]))
        base.append((10 * s) % 11 % 10)
    return "".join(map(str, base))


def nome_unico() -> str:
    return f"{NOME_PREFIX} {uuid.uuid4().hex[:8]}"


def soup(html: str) -> BeautifulSoup:
    return BeautifulSoup(html, "html.parser")


def campos_do_form(form) -> dict[str, str]:
    """Serializa um <form> como o navegador faria (sem os arquivos)."""
    data: dict[str, str] = {}
    for el in form.find_all(["input", "textarea", "select"]):
        name = el.get("name")
        if not name or el.get("disabled") is not None:
            continue
        tipo = (el.get("type") or "").lower()
        if el.name == "input" and tipo in ("submit", "button", "file", "image", "reset"):
            continue
        if el.name == "input" and tipo in ("checkbox", "radio"):
            if el.get("checked") is not None:
                data[name] = el.get("value", "on")
            continue
        if el.name == "textarea":
            data[name] = el.text
        elif el.name == "select":
            opt = el.find("option", selected=True) or el.find("option")
            data[name] = opt.get("value", opt.text) if opt else ""
        else:
            data[name] = el.get("value", "")
    return data


def achar_form(html: str, acao: str):
    """Form cuja ação (campo action no wp-admin, festa_acao no portal) é `acao`."""
    for form in soup(html).find_all("form"):
        for nome in ("action", "festa_acao"):
            inp = form.find("input", attrs={"name": nome, "value": acao})
            if inp is not None:
                return form
    return None


def msg_do_redirect(resp: requests.Response) -> str:
    loc = resp.headers.get("Location", "")
    return parse_qs(urlparse(loc).query).get("msg", [""])[0]


@dataclass
class Resposta:
    status: int
    corpo: dict | str

    @property
    def ok(self) -> bool:
        return isinstance(self.corpo, dict) and self.corpo.get("success") is True

    @property
    def dados(self) -> dict:
        return self.corpo.get("data", {}) if isinstance(self.corpo, dict) else {}

    @property
    def mensagem(self) -> str:
        return str(self.dados.get("message", ""))

    @property
    def campo(self) -> str:
        return str(self.dados.get("field", ""))


def _resposta(r: requests.Response) -> Resposta:
    try:
        return Resposta(r.status_code, r.json())
    except ValueError:
        return Resposta(r.status_code, r.text)


# ---------------------------------------------------------------------------
# Participante (front-end, sessão própria por CPF + senha)
# ---------------------------------------------------------------------------

@dataclass
class Participante:
    paginas: dict
    db: Db
    nome: str = field(default_factory=nome_unico)
    cpf: str = field(default_factory=gerar_cpf)
    ua: str = field(default_factory=lambda: f"festa-teste-participante/{uuid.uuid4().hex[:6]}")
    senha: str = ""
    id: int = 0

    def __post_init__(self):
        self.http = requests.Session()
        self.http.headers["User-Agent"] = self.ua
        self._csrf: str | None = None

    # -- infraestrutura -----------------------------------------------------

    # Participantes não são usuários do WordPress: o nonce dos formulários é
    # o mesmo para qualquer visitante, então basta buscá-lo uma vez.
    _nonce_anonimo: ClassVar[str | None] = None

    def nonce(self) -> str:
        if Participante._nonce_anonimo is None:
            html = self.http.get(self.paginas["pre"]).text
            m = re.search(r'"nonce":"([a-f0-9]+)"', html)
            assert m, "nonce do formulário não encontrado na página de pré-cadastro"
            Participante._nonce_anonimo = m.group(1)
        return Participante._nonce_anonimo

    def _ler_painel(self) -> str:
        html = self.http.get(self.paginas["painel"]).text
        inp = soup(html).find("input", attrs={"name": "festa_csrf"})
        self._csrf = inp.get("value", "") if inp else ""
        m = re.search(r"Protocolo <strong>#(\d+)", html)
        if m:
            self.id = int(m.group(1))
        return html

    def csrf(self) -> str:
        """Token da sessão atual (relido só quando a sessão muda)."""
        if self._csrf is None:
            self._ler_painel()
        return self._csrf or ""

    def ajax(self, acao: str, dados: dict | None = None, arquivos: dict | None = None,
             nonce: str | None = None, csrf: str | None = None) -> Resposta:
        payload = {"action": acao, "nonce": self.nonce() if nonce is None else nonce}
        if csrf is not None:
            payload["festa_csrf"] = csrf
        payload.update(dados or {})
        return _resposta(self.http.post(AJAX, data=payload, files=arquivos))

    # -- ações ---------------------------------------------------------------

    def pre_cadastro(self, **extra) -> Resposta:
        dados = {"cpf": self.cpf, "nome": self.nome, "festa_hp": ""}
        dados.update(extra)
        r = self.ajax("festa_auth_ini_inscricao", dados)
        self._csrf = None
        if r.ok:
            self.senha = r.dados.get("senha", "")
            self._ler_painel()  # protocolo e token da sessão recém-aberta
        return r

    def login(self, senha: str | None = None, cpf: str | None = None) -> Resposta:
        self._csrf = None
        return self.ajax("festa_auth_login", {
            "cpf": cpf or self.cpf,
            "senha": self.senha if senha is None else senha,
        })

    def logout(self) -> Resposta:
        self._csrf = None
        return self.ajax("festa_auth_logout")

    def dados_finalizacao(self, **override) -> dict:
        dados = {
            "email": f"{uuid.uuid4().hex[:8]}@example.test",
            "telefone": "(11) 98765-4321",
            "grupo": f"Grupo {self.nome}",
            "qtd_participantes": "12",
            "coccao": "gas",
            "espaco": "1",
            "aceite": "1",
        }
        dados.update(override)
        return {k: v for k, v in dados.items() if v is not None}

    def finalizar(self, arquivos: dict | None = None, csrf: str | None = None, **override) -> Resposta:
        return self.ajax(
            "festa_finalizar_inscricao",
            self.dados_finalizacao(**override),
            arquivos=arquivos,
            csrf=self.csrf() if csrf is None else csrf,
        )

    def enviar_documento(self, tipo: str, arq=None, inscricao_id: int | None = None,
                         csrf: str | None = None) -> Resposta:
        return self.ajax(
            "festa_upload_documentos",
            {"document_type": tipo, "inscricao_id": str(self.id if inscricao_id is None else inscricao_id)},
            arquivos={"documento": arq or pdf()},
            csrf=self.csrf() if csrf is None else csrf,
        )

    def trocar_senha(self, atual: str, nova: str, nova2: str | None = None) -> Resposta:
        return self.ajax(
            "festa_auth_trocar_senha",
            {"senha_atual": atual, "senha_nova": nova, "senha_nova2": nova if nova2 is None else nova2},
            csrf=self.csrf(),
        )

    # -- leitura do painel ---------------------------------------------------

    def painel(self) -> str:
        return self._ler_painel()

    def logado(self) -> bool:
        return "Minha inscrição" in self.painel()

    def links_documentos(self) -> list[str]:
        return [a["href"] for a in soup(self.painel()).select("a.festa-doc-link")]

    def inscrito(self) -> "Participante":
        """Pré-cadastro + finalização com dados válidos."""
        r = self.pre_cadastro()
        assert r.ok, f"pré-cadastro falhou: {r.corpo}"
        r = self.finalizar()
        assert r.ok, f"finalização falhou: {r.corpo}"
        return self


# ---------------------------------------------------------------------------
# Equipe: base comum para wp-admin e portal
# ---------------------------------------------------------------------------

class Equipe:
    """Ações da equipe sobre uma inscrição, submetendo os formulários reais."""

    def __init__(self, ua: str):
        self.http = requests.Session()
        self.http.headers["User-Agent"] = ua

    # As subclasses dizem onde ficam as telas.
    def url_inscricao(self, post_id: int) -> str: ...
    def url_lista(self, **args) -> str: ...
    def url_colchoes(self) -> str: ...

    def tela_inscricao(self, post_id: int) -> str:
        return self.http.get(self.url_inscricao(post_id)).text

    def enviar_form(self, html: str, acao: str, base_url: str, extra: dict | None = None,
                    arquivos: dict | None = None) -> requests.Response:
        form = achar_form(html, acao)
        assert form is not None, f"formulário '{acao}' não está na tela"
        dados = campos_do_form(form)
        dados.update(extra or {})
        destino = urljoin(base_url, form.get("action") or base_url)
        return self.http.post(destino, data=dados, files=arquivos, allow_redirects=False)

    def acao(self, post_id: int, acao: str, extra: dict | None = None, arquivos: dict | None = None) -> requests.Response:
        url = self.url_inscricao(post_id)
        return self.enviar_form(self.http.get(url).text, acao, url, extra, arquivos)

    def tem_form(self, post_id: int, acao: str) -> bool:
        return achar_form(self.tela_inscricao(post_id), acao) is not None

    # -- fluxo -------------------------------------------------------------

    def deferir(self, post_id: int, arq=None):
        return self.acao(post_id, "festa_deferir", arquivos={"documento": arq or pdf("deferimento.pdf")})

    def tenda(self, post_id: int, numero: str = "A-12"):
        return self.acao(post_id, "festa_tenda", {"numero_tenda": numero})

    def aprovar_recibo(self, post_id: int):
        return self.acao(post_id, "festa_recibo")

    def indeferir(self, post_id: int, justificativa: str):
        return self.acao(post_id, "festa_indeferir", {"justificativa": justificativa})

    def reabrir(self, post_id: int, motivo: str):
        return self.acao(post_id, "festa_reabrir", {"motivo": motivo})

    def chamar_fila(self) -> requests.Response:
        """Botão "Chamar próximo da fila", na lista filtrada pela lista de espera."""
        url = self.url_lista(status="lista_espera")
        return self.enviar_form(self.http.get(url).text, "festa_chamar_fila", url)

    def observacoes(self, post_id: int, texto: str):
        return self.acao(post_id, "festa_notes", {"observacoes": texto})

    def contato(self, post_id: int, email: str, telefone: str):
        return self.acao(post_id, "festa_contato", {"email": email, "telefone": telefone})

    def nova_senha(self, post_id: int):
        return self.acao(post_id, "festa_senha")

    def anexar(self, post_id: int, doc: str, arq=None):
        html = self.tela_inscricao(post_id)
        for form in soup(html).find_all("form"):
            if form.find("input", attrs={"name": "doc", "value": doc}):
                dados = campos_do_form(form)
                destino = urljoin(self.url_inscricao(post_id), form.get("action"))
                return self.http.post(destino, data=dados, files={"documento": arq or pdf()}, allow_redirects=False)
        raise AssertionError(f"formulário de anexo '{doc}' não está na tela")

    # -- Defesa Civil: busca por CPF e confirmação ------------------------

    def buscar_cpf(self, cpf: str) -> str:
        url = self.url_colchoes()
        r = self.enviar_form(self.http.get(url).text, "festa_colchoes_busca", url, {"cpf": cpf})
        return r.text

    def confirmar_colchoes(self, cpf: str):
        html = self.buscar_cpf(cpf)
        return self.enviar_form(html, "festa_colchoes", self.url_colchoes())


class EquipeWP(Equipe):
    """Usuário do WordPress (Comissão, Defesa Civil, Administrador, Assinante)."""

    def __init__(self, login: str, senha: str, user_id: int = 0):
        super().__init__(f"festa-teste-wp/{login}")
        self.login_nome = login
        self.user_id = user_id
        self.http.cookies.set("wordpress_test_cookie", "WP Cookie check")
        r = self.http.post(BASE + "/wp-login.php", data={
            "log": login, "pwd": senha, "wp-submit": "Acessar",
            "redirect_to": ADMIN, "testcookie": "1",
        }, allow_redirects=False)
        assert r.status_code == 302 and any(c.startswith("wordpress_logged_in") for c in self.http.cookies.keys()), \
            f"login do WordPress falhou para {login}"

    def url_inscricao(self, post_id: int) -> str:
        return f"{ADMIN}admin.php?page=festa-inscricoes&view={post_id}"

    def url_lista(self, **args) -> str:
        extra = "".join(f"&{k}={v}" for k, v in args.items())
        return f"{ADMIN}admin.php?page=festa-inscricoes{extra}"

    def url_colchoes(self) -> str:
        return f"{ADMIN}admin.php?page=festa_busca_colchoes"

    def pagina(self, slug: str) -> requests.Response:
        return self.http.get(f"{ADMIN}admin.php?page={slug}")

    def nonces(self, acoes: list[str]) -> dict[str, str]:
        """
        Nonces válidos para ESTA sessão (mesmo usuário, mesmo token de login),
        gerados pelo próprio WordPress. Servem para provar que uma recusa vem
        da falta de permissão, e não de um nonce inválido.
        """
        cookie = next(unquote(v) for k, v in self.http.cookies.items() if k.startswith("wordpress_logged_in"))
        entrada = json.dumps({"cookie": cookie, "uid": self.user_id, "acoes": acoes})
        php = (
            "<?php\n$in = json_decode(" + _php_literal(entrada) + ", true);\n"
            "$_COOKIE[LOGGED_IN_COOKIE] = $in['cookie'];\n"
            "wp_set_current_user((int) $in['uid']);\n"
            "$out = [];\nforeach ($in['acoes'] as $a) { $out[$a] = wp_create_nonce($a); }\n"
            "echo \"\\n@@FESTA_JSON@@\" . wp_json_encode($out) . \"\\n\";\n"
        )
        proc = wp_eval(php)
        if "@@FESTA_JSON@@" not in proc.stdout:
            raise RuntimeError(f"geração de nonces falhou:\n{proc.stdout}\n{proc.stderr}")
        return json.loads(proc.stdout.split("@@FESTA_JSON@@", 1)[1].strip())

    def salvar_config(self, **mudancas) -> requests.Response:
        """Envia o formulário de Configurações com os campos alterados (None = campo ausente)."""
        url = f"{ADMIN}admin.php?page=festa-configuracoes"
        form = achar_form(self.http.get(url).text, "festa_save_settings")
        assert form is not None, "formulário de configurações não está na tela"
        dados = campos_do_form(form)
        for k, v in mudancas.items():
            if v is None:
                dados.pop(k, None)
            else:
                dados[k] = v
        return self.http.post(urljoin(url, form.get("action")), data=dados, allow_redirects=False)

    def admin_post(self, acao: str, dados: dict | None = None, metodo: str = "post") -> requests.Response:
        payload = {"action": acao}
        payload.update(dados or {})
        if metodo == "get":
            return self.http.get(ADMIN_POST, params=payload, allow_redirects=False)
        return self.http.post(ADMIN_POST, data=payload, allow_redirects=False)


class EquipePortal(Equipe):
    """Conta do portal externo (login próprio, fora do WordPress)."""

    def __init__(self, portal_url: str, login: str | None = None, senha: str | None = None):
        super().__init__(f"festa-teste-portal/{login}")
        self.portal = portal_url
        self.ultima: requests.Response | None = None
        if login is not None:
            self.ultima = self.entrar(login, senha or "")

    def _url(self, **args) -> str:
        sep = "&" if "?" in self.portal else "?"
        return self.portal + ("" if not args else sep + "&".join(f"{k}={v}" for k, v in args.items()))

    def pre_token(self) -> str:
        inp = soup(self.http.get(self.portal).text).find("input", attrs={"name": "_pre"})
        return inp.get("value", "") if inp else ""

    def entrar(self, login: str, senha: str, pre: str | None = None, headers: dict | None = None) -> requests.Response:
        return self.http.post(self.portal, data={
            "festa_acao": "entrar", "_pre": self.pre_token() if pre is None else pre,
            "login": login, "senha": senha,
        }, headers=headers, allow_redirects=False)

    def csrf(self) -> str:
        inp = soup(self.http.get(self._url(tela="conta")).text).find("input", attrs={"name": "_csrf"})
        return inp.get("value", "") if inp else ""

    def post(self, acao: str, dados: dict | None = None, csrf: str | None = None,
             headers: dict | None = None, tela: str | None = None) -> requests.Response:
        payload = {"festa_acao": acao, "_csrf": self.csrf() if csrf is None else csrf}
        payload.update(dados or {})
        url = self._url(tela=tela) if tela else self.portal
        return self.http.post(url, data=payload, headers=headers, allow_redirects=False)

    def tela(self, nome: str, **args) -> requests.Response:
        return self.http.get(self._url(tela=nome, **args), allow_redirects=False)

    def baixar(self, **args) -> requests.Response:
        return self.http.get(self._url(**args), allow_redirects=False)

    def url_inscricao(self, post_id: int) -> str:
        return self._url(tela="inscricoes", view=post_id)

    def url_lista(self, **args) -> str:
        return self._url(tela="inscricoes", **args)

    def url_colchoes(self) -> str:
        return self._url(tela="colchoes")


# ---------------------------------------------------------------------------
# Atacante em outra rede
# ---------------------------------------------------------------------------

_PHP_HTTP = r"""<?php
$in = json_decode(@@IN@@, true);
$ch = curl_init($in['url']);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query($in['data']),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_USERAGENT => $in['ua'],
]);
$body = curl_exec($ch);
echo json_encode(['status' => curl_getinfo($ch, CURLINFO_HTTP_CODE), 'body' => $body]);
"""


def url_da_pasta_privada(amb: dict) -> str | None:
    """Endereço da pasta privada do plugin, ou None quando ela fica fora da raiz do site."""
    raiz = amb["abspath"].rstrip("/")
    pasta = amb["uploads_dir"].rstrip("/")
    if not pasta.startswith(raiz + "/"):
        return None
    return BASE + pasta[len(raiz):]


def _php_literal(texto: str) -> str:
    return "'" + texto.replace("\\", "\\\\").replace("'", "\\'") + "'"


def post_de_outra_rede(path: str, dados: dict, ua: str = "festa-teste-atacante") -> Resposta:
    """POST feito de dentro do container do WordPress: o site vê outro IP (127.0.0.1)."""
    entrada = json.dumps({"url": "http://localhost" + path, "data": dados, "ua": ua})
    proc = compose("exec", "-T", "wordpress", "php", stdin=_PHP_HTTP.replace("@@IN@@", _php_literal(entrada)))
    out = json.loads(proc.stdout)
    try:
        return Resposta(out["status"], json.loads(out["body"]))
    except (ValueError, TypeError):
        return Resposta(out["status"], out["body"])


# ---------------------------------------------------------------------------
# Mailpit: a caixa de entrada de teste do docker compose
# ---------------------------------------------------------------------------

class Mailpit:
    """Lê os e-mails que o site enviou (API do Mailpit)."""

    def __init__(self, base: str = MAILPIT):
        self.base = base
        self.http = requests.Session()

    def buscar(self, consulta: str) -> list[dict]:
        r = self.http.get(f"{self.base}/api/v1/search", params={"query": consulta, "limit": 200})
        r.raise_for_status()
        return r.json().get("messages", [])

    def mensagens_para(self, email: str, assunto: str = "", espera: float = 8.0) -> list[dict]:
        """
        E-mails para o endereço (com o trecho no assunto), com o corpo. Espera
        um pouco: o envio é feito pelo PHP durante a requisição, mas a
        indexação no Mailpit pode levar alguns instantes.
        """
        import time

        limite = time.monotonic() + espera
        while True:
            achados = [m for m in self.buscar(f'to:"{email}"') if assunto in m.get("Subject", "")]
            if achados or time.monotonic() >= limite:
                break
            time.sleep(0.4)
        return [self.mensagem(m["ID"]) for m in achados]

    def mensagem(self, msg_id: str) -> dict:
        r = self.http.get(f"{self.base}/api/v1/message/{msg_id}")
        r.raise_for_status()
        return r.json()

    def total(self) -> int:
        r = self.http.get(f"{self.base}/api/v1/messages", params={"limit": 1})
        r.raise_for_status()
        return int(r.json().get("total", 0))

    def apagar_tudo(self) -> None:
        self.http.delete(f"{self.base}/api/v1/messages").raise_for_status()
