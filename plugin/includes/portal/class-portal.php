<?php

declare(strict_types=1);

namespace Festa\Portal;

use Festa\Admin\AdminActions;
use Festa\Admin\AdminDefesa;
use Festa\Admin\Ctx;
use Festa\Admin\Dashboard;
use Festa\Admin\Panel;
use Festa\Audit\AuditLog;
use Festa\Fields\Fields;
use Festa\Settings\Settings;

/**
 * Portal externo de gerenciamento (fora do wp-admin).
 *
 * Atende um endereço próprio do site (ex.: /portal-festa-dos-amigos/) com
 * login de contas do portal, e mostra ali as mesmas telas do painel.
 *
 * Camadas de proteção:
 *  1. A página é um documento à parte: não carrega o tema nem scripts de
 *     outros plugins, e sai com cabeçalhos restritivos (CSP sem inline,
 *     sem iframe, sem cache, sem indexação).
 *  2. Sessão por cookie HttpOnly + Secure + SameSite=Strict; expira por
 *     inatividade e por tempo máximo; o banco guarda só o hash do token.
 *  3. Todo envio (POST) precisa do token CSRF da sessão e de origem igual
 *     à do site. Nada é alterado por GET.
 *  4. Cada ação tem uma permissão exigida; o que não está na lista é
 *     recusado. As telas escondem o que o usuário não pode, mas quem decide
 *     é sempre o servidor.
 *  5. Tentativas de acesso erradas bloqueiam a conta e o IP por um tempo, e
 *     a resposta não revela se o usuário existe.
 */
final class Portal
{
    public const OPTION     = 'festa_portal';
    public const COOKIE     = 'festa_portal';
    public const COOKIE_PRE = 'festa_portal_pre';

    private static $user    = null;
    private static $session = null;
    private static $csrf_ok = false;

    /**
     * Mensagem de erro da tela "Minha conta" (troca de senha).
     *
     * @var string
     */
    private static $conta_error = '';

    public static function boot_hooks()
    {
        add_action('wp_loaded', [self::class, 'maybe_handle'], 1);
    }

    /* ---------------------------------------------------------------------
     * Configuração e endereços
     * ------------------------------------------------------------------- */

    public static function settings()
    {
        return wp_parse_args(
            (array) get_option(self::OPTION, []),
            [
                'enabled' => 0,
                'slug'    => 'portal-festa-dos-amigos',
            ]
        );
    }

    public static function enabled()
    {
        $s = self::settings();
        return ! empty($s['enabled']);
    }

    public static function slug()
    {
        $s    = self::settings();
        $slug = sanitize_title((string) $s['slug']);
        return '' !== $slug ? $slug : 'portal-festa-dos-amigos';
    }

    /**
     * Com links permanentes "bonitos" o portal tem endereço próprio; sem
     * eles, cai em /?festa_portal=1.
     */
    public static function pretty()
    {
        return '' !== (string) get_option('permalink_structure');
    }

    public static function url($args = [])
    {
        if (self::pretty()) {
            $base = home_url('/' . self::slug() . '/');
        } else {
            $base = home_url('/');
            $args = ['festa_portal' => '1'] + $args;
        }
        return add_query_arg(urlencode_deep($args), $base);
    }

    /**
     * Endereço seguro para redirecionar depois de uma ação.
     *
     * Aceita só endereços do próprio portal e, em vez de reaproveitar o texto
     * recebido, remonta o endereço a partir dos parâmetros: o destino é
     * sempre este site e este portal, venha o que vier no formulário.
     */
    public static function safe_url($url)
    {
        $p    = wp_parse_url((string) $url);
        $home = wp_parse_url(home_url('/'));
        if (! is_array($p) || empty($home['host'])) {
            return self::url();
        }
        if (! empty($p['host']) && strtolower($p['host']) !== strtolower($home['host'])) {
            return self::url();
        }

        $q = [];
        parse_str(isset($p['query']) ? $p['query'] : '', $q);

        if (self::pretty()) {
            $want = (string) wp_parse_url(self::url(), PHP_URL_PATH);
            if (! isset($p['path']) || untrailingslashit($p['path']) !== untrailingslashit($want)) {
                return self::url();
            }
        } elseif (! isset($q['festa_portal'])) {
            return self::url();
        }

        unset($q['festa_portal']);
        return self::url(array_filter($q, 'is_scalar'));
    }

    private static function is_portal_request()
    {
        if (! self::pretty()) {
            return isset($_GET['festa_portal']);
        }
        $uri  = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
        $path = (string) wp_parse_url($uri, PHP_URL_PATH);
        $home = (string) wp_parse_url(home_url('/'), PHP_URL_PATH);
        $home = '' !== $home ? $home : '/';
        if (0 !== strpos($path, $home)) {
            return false;
        }
        $rel = trim(substr($path, strlen($home)), '/');
        if (0 === strpos($rel, 'index.php/')) {
            $rel = substr($rel, 10);
        }
        return rawurldecode($rel) === self::slug();
    }

    /**
     * O site é servido por HTTPS? Além do is_ssl(), considera o endereço
     * configurado do site e o cabeçalho do proxy: errar para "sim" só deixa
     * o cookie mais restrito.
     */
    public static function is_https()
    {
        if (is_ssl() || 'https' === wp_parse_url(home_url(), PHP_URL_SCHEME)) {
            return true;
        }
        return isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && 'https' === strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']);
    }

    /* ---------------------------------------------------------------------
     * Usuário atual
     * ------------------------------------------------------------------- */

    public static function user()
    {
        return self::$user;
    }

    public static function can($perm)
    {
        return self::$user && in_array($perm, self::$user['perms_list'], true);
    }

    public static function csrf_ok()
    {
        return self::$csrf_ok;
    }

    public static function csrf_token()
    {
        return self::$session ? (string) self::$session['csrf'] : '';
    }

    /* ---------------------------------------------------------------------
     * Cookies
     * ------------------------------------------------------------------- */

    /**
     * Com HTTPS o cookie usa o prefixo __Host-: o navegador só o aceita se
     * vier do próprio site, com Secure e sem Domain — outro subdomínio não
     * consegue plantar um cookie de sessão falso.
     */
    private static function cookie_name($base)
    {
        return self::is_https() ? '__Host-' . $base : $base;
    }

    private static function set_cookie($base, $value, $expires = 0)
    {
        if (headers_sent()) {
            return;
        }
        setcookie(
            self::cookie_name($base),
            $value,
            [
                'expires'  => $expires,
                'path'     => '/',
                'secure'   => self::is_https(),
                'httponly' => true,
                'samesite' => 'Strict',
            ]
        );
    }

    private static function clear_cookie($base)
    {
        self::set_cookie($base, '', time() - DAY_IN_SECONDS);
        unset($_COOKIE[self::cookie_name($base)]);
    }

    private static function read_cookie($base)
    {
        $name = self::cookie_name($base);
        return isset($_COOKIE[$name]) && is_string($_COOKIE[$name]) ? $_COOKIE[$name] : '';
    }

    /* ---------------------------------------------------------------------
     * Entrada da requisição
     * ------------------------------------------------------------------- */

    public static function maybe_handle()
    {
        if (is_admin() || wp_doing_ajax() || wp_doing_cron() || (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) || (defined('WP_CLI') && WP_CLI)) {
            return;
        }
        if (! self::is_portal_request()) {
            return;
        }
        // Desligado: o WordPress segue o caminho normal e responde 404.
        if (! self::enabled()) {
            return;
        }
        self::handle();
        exit;
    }

    private static function handle()
    {
        if (! defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }
        Ctx::set(new CtxPortal());
        self::send_headers();

        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';
        if (! in_array($method, ['GET', 'HEAD', 'POST'], true)) {
            status_header(405);
            header('Allow: GET, HEAD, POST');
            return;
        }

        self::authenticate();

        if ('POST' === $method) {
            self::handle_post();
        } else {
            self::handle_get();
        }
    }

    private static function send_headers()
    {
        nocache_headers();
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private');
        header('Pragma: no-cache');
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: same-origin');
        header('X-Robots-Tag: noindex, nofollow, noarchive');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
        header('Cross-Origin-Opener-Policy: same-origin');
        header('Cross-Origin-Resource-Policy: same-origin');
    }

    /**
     * Política de conteúdo das páginas do portal: nada de fora do site, e
     * nenhum script ou estilo embutido no HTML. Se alguém conseguisse injetar
     * marcação numa tela, o navegador não a executaria.
     */
    private static function send_csp()
    {
        if (headers_sent()) {
            return;
        }
        $src   = "'self'";
        $asset = wp_parse_url(FESTA_PLUGIN_URL);
        $home  = (string) wp_parse_url(home_url(), PHP_URL_HOST);
        if (! empty($asset['host']) && strtolower($asset['host']) !== strtolower($home)) {
            $src .= ' ' . (isset($asset['scheme']) ? $asset['scheme'] : 'https') . '://' . $asset['host'] . (isset($asset['port']) ? ':' . (int) $asset['port'] : '');
        }
        header("Content-Security-Policy: default-src 'none'; script-src {$src}; style-src {$src}; img-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
        header('Content-Type: text/html; charset=UTF-8');
    }

    private static function authenticate()
    {
        $token = self::read_cookie(self::COOKIE);
        if ('' === $token) {
            return;
        }
        $found = Store::find_session($token);
        if (! $found) {
            self::clear_cookie(self::COOKIE);
            return;
        }
        self::$session = $found['session'];
        self::$user    = $found['user'];
    }

    /**
     * O envio partiu de uma página deste site?
     *
     * Complementa o token CSRF: recusa o que o navegador declara ter vindo de
     * outro site (Sec-Fetch-Site) ou de outra origem (Origin/Referer).
     */
    private static function same_origin()
    {
        if (isset($_SERVER['HTTP_SEC_FETCH_SITE']) && ! in_array($_SERVER['HTTP_SEC_FETCH_SITE'], ['same-origin', 'none'], true)) {
            return false;
        }

        $hosts = [strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST))];
        if (! empty($_SERVER['HTTP_HOST'])) {
            $hosts[] = strtolower(preg_replace('/:\d+$/', '', (string) $_SERVER['HTTP_HOST']));
        }

        foreach (['HTTP_ORIGIN', 'HTTP_REFERER'] as $header) {
            if (empty($_SERVER[$header]) || 'null' === $_SERVER[$header]) {
                continue;
            }
            $host = strtolower((string) wp_parse_url((string) $_SERVER[$header], PHP_URL_HOST));
            return '' !== $host && in_array($host, $hosts, true);
        }
        // Sem Origin nem Referer: o token CSRF decide.
        return true;
    }

    /* ---------------------------------------------------------------------
     * POST: login e ações
     * ------------------------------------------------------------------- */

    /**
     * Ações aceitas por POST: permissão exigida, função e rótulo do registro.
     * O que não estiver aqui é recusado.
     */
    private static function post_actions()
    {
        return [
            'festa_busca'          => ['ver', [Panel::class, 'search'], ''],
            'festa_deferir'        => ['analisar', [AdminActions::class, 'deferir'], 'Deferiu a inscrição'],
            'festa_indeferir'      => ['analisar', [AdminActions::class, 'indeferir'], 'Indeferiu a inscrição'],
            'festa_reabrir'        => ['analisar', [AdminActions::class, 'reabrir'], 'Reabriu a inscrição para análise'],
            'festa_chamar_fila'    => ['analisar', [AdminActions::class, 'chamar_fila'], 'Chamou o próximo da lista de espera'],
            'festa_tenda'          => ['tenda', [AdminActions::class, 'tenda'], 'Informou o número da tenda'],
            'festa_recibo'         => ['recibo', [AdminActions::class, 'aprovar_recibo'], 'Aprovou o recibo da tenda'],
            'festa_colchoes'       => ['colchoes', [AdminDefesa::class, 'confirmar'], 'Confirmou a entrega de colchões'],
            'festa_colchoes_busca' => ['colchoes', [AdminDefesa::class, 'buscar'], 'Buscou uma inscrição por CPF'],
            'festa_notes'          => ['editar', [Panel::class, 'save_notes'], 'Salvou as observações'],
            'festa_contato'        => ['editar', [Panel::class, 'save_contato'], 'Corrigiu o contato'],
            'festa_senha'          => ['editar', [Panel::class, 'reset_password'], 'Gerou nova senha para o participante'],
            'festa_anexar'         => ['editar', [Panel::class, 'attach'], 'Anexou um documento'],
            'festa_delete'         => ['excluir', [Panel::class, 'delete'], 'Excluiu a inscrição'],
            'festa_bulk'           => ['excluir', [Panel::class, 'bulk'], 'Ação em massa'],
            'festa_save_settings'  => ['config', [Settings::class, 'save'], 'Alterou as configurações'],
            // Da própria conta: qualquer usuário do portal ('' = sem permissão específica).
            'conta_senha'          => ['', [self::class, 'change_password'], ''],
            'conta_sessoes'        => ['', [self::class, 'end_other_sessions'], 'Encerrou as outras sessões'],
            'sair'                 => ['', [self::class, 'logout'], ''],
        ];
    }

    /**
     * Ações só de leitura aceitas por GET (?baixar=…).
     */
    private static function download_actions()
    {
        return [
            // A permissão de um documento depende de qual é (ver Panel::serve_file).
            'festa_arquivo'    => ['', [Panel::class, 'serve_file']],
            'festa_zip'        => ['ver', [Panel::class, 'zip']],
            'festa_export'     => ['exportar', [Panel::class, 'export_csv']],
            'festa_export_pdf' => ['exportar', [Panel::class, 'export_pdf']],
        ];
    }

    private static function handle_post()
    {
        if (! self::same_origin()) {
            // Só registra quando há sessão: sem ela, qualquer um na internet
            // poderia encher o registro mandando envios forjados.
            if (self::$user) {
                Store::log('origem', 'Envio vindo de outro site foi recusado.', self::$user);
            }
            self::stop('Envio recusado: a requisição não partiu desta página.', 403);
        }
        if (empty($_POST)) {
            self::stop('O envio passou do tamanho aceito pelo servidor. Use arquivos menores e tente de novo.', 413);
        }

        $acao = isset($_POST['festa_acao']) && is_string($_POST['festa_acao']) ? sanitize_key($_POST['festa_acao']) : '';

        if (! self::$user) {
            if ('entrar' === $acao) {
                self::login();
                return;
            }
            self::render_login('Sua sessão terminou. Entre novamente para continuar.', 401);
            return;
        }

        $token = isset($_POST['_csrf']) && is_string($_POST['_csrf']) ? $_POST['_csrf'] : '';
        if ('' === $token || ! hash_equals(self::csrf_token(), $token)) {
            Store::log('csrf', 'Envio sem o token da sessão foi recusado.', self::$user);
            self::stop('Não foi possível confirmar o envio. Volte, recarregue a página e tente de novo.', 403);
        }
        self::$csrf_ok = true;

        if ('entrar' === $acao) {
            self::go([]);
        }
        if (self::$user['must_change'] && ! in_array($acao, ['conta_senha', 'sair'], true)) {
            self::go(['tela' => 'conta']);
        }

        $map = self::post_actions();
        if (! isset($map[$acao])) {
            self::stop('Ação desconhecida.', 400);
        }
        list($perm, $callback, $label) = $map[$acao];

        if ('' !== $perm && ! self::can($perm)) {
            Store::log('negado', ($label ? $label : $acao) . ' — sem a permissão "' . $perm . '".', self::$user);
            self::stop('Seu usuário não tem permissão para esta ação.', 403);
        }
        if ($label) {
            Store::log('acao', trim($label . ' ' . self::action_detail($acao)), self::$user);
        }

        call_user_func($callback);

        // As ações redirecionam ao terminar. Só chegam aqui as que precisam
        // reexibir a tela (troca de senha com erro, busca por CPF).
        self::render_screen();
    }

    /**
     * Complemento do registro: de qual inscrição e o quê.
     */
    private static function action_detail($acao)
    {
        $bits = [];
        $id   = isset($_POST['id']) && is_scalar($_POST['id']) ? absint($_POST['id']) : 0;
        if ($id) {
            $bits[] = '(#' . $id . ')';
        }
        if ('festa_bulk' === $acao) {
            $n      = isset($_POST['ids']) && is_array($_POST['ids']) ? count($_POST['ids']) : 0;
            $what   = isset($_POST['bulk_action']) && is_string($_POST['bulk_action']) ? sanitize_key($_POST['bulk_action']) : '';
            $bits[] = sprintf('"%s" em %d inscrição(ões)', $what, $n);
        }
        if ('festa_anexar' === $acao && isset($_POST['doc']) && is_string($_POST['doc'])) {
            $bits[] = '— ' . Fields::document_label(sanitize_key($_POST['doc']));
        }
        return implode(' ', $bits);
    }

    /* ---------------------------------------------------------------------
     * GET: telas e downloads
     * ------------------------------------------------------------------- */

    private static function handle_get()
    {
        $baixar = isset($_GET['baixar']) && is_string($_GET['baixar']) ? sanitize_key($_GET['baixar']) : '';

        if (! self::$user) {
            self::render_login('', '' !== $baixar ? 403 : 200);
            return;
        }

        $tela = self::requested_screen();
        if (self::$user['must_change'] && ('conta' !== $tela || '' !== $baixar)) {
            self::go(['tela' => 'conta']);
        }

        if ('' !== $baixar) {
            $map = self::download_actions();
            if (! isset($map[$baixar])) {
                self::stop('Endereço inválido.', 404);
            }
            list($perm, $callback) = $map[$baixar];
            if ('' !== $perm && ! self::can($perm)) {
                Store::log('negado', $baixar . ' — sem a permissão "' . $perm . '".', self::$user);
                self::stop('Seu usuário não tem permissão para baixar isto.', 403);
            }
            self::log_download($baixar);
            call_user_func($callback);
            return;
        }

        self::render_screen();
    }

    /**
     * Registra a saída de dados do sistema: exportações, ZIP e download de
     * documento. (Abrir um documento na tela não é registrado: as miniaturas
     * gerariam uma linha por imagem.)
     */
    private static function log_download($baixar)
    {
        $id = isset($_GET['id']) && is_scalar($_GET['id']) ? absint($_GET['id']) : 0;
        if ('festa_export' === $baixar) {
            Store::log('exportacao', 'Exportou as inscrições em CSV.', self::$user);
        } elseif ('festa_export_pdf' === $baixar) {
            Store::log('exportacao', 'Exportou as inscrições em PDF.', self::$user);
        } elseif ('festa_zip' === $baixar) {
            Store::log('exportacao', 'Baixou os documentos em ZIP (#' . $id . ').', self::$user);
        } elseif ('festa_arquivo' === $baixar && ! empty($_GET['dl'])) {
            $doc = isset($_GET['doc']) && is_string($_GET['doc']) ? sanitize_key($_GET['doc']) : '';
            Store::log('exportacao', 'Baixou o documento "' . Fields::document_label($doc) . '" (#' . $id . ').', self::$user);
        }
    }

    /**
     * Redireciona para uma tela do portal e encerra.
     */
    private static function go($args)
    {
        wp_safe_redirect(self::url($args), 303);
        exit;
    }

    /* ---------------------------------------------------------------------
     * Login / logout / senha
     * ------------------------------------------------------------------- */

    /**
     * Token do formulário de login (ainda não existe sessão): um valor
     * aleatório num cookie SameSite=Strict e, no formulário, a assinatura
     * dele. Um formulário montado em outro site não tem como enviar os dois.
     */
    private static function pre_token()
    {
        $val = self::read_cookie(self::COOKIE_PRE);
        if (! preg_match('/^[a-f0-9]{64}$/', $val)) {
            $val = bin2hex(random_bytes(32));
            self::set_cookie(self::COOKIE_PRE, $val);
        }
        return hash_hmac('sha256', $val, wp_salt('nonce'));
    }

    private static function check_pre_token()
    {
        $sent = isset($_POST['_pre']) && is_string($_POST['_pre']) ? $_POST['_pre'] : '';
        $val  = self::read_cookie(self::COOKIE_PRE);
        if ('' === $sent || ! preg_match('/^[a-f0-9]{64}$/', $val)) {
            return false;
        }
        return hash_equals(hash_hmac('sha256', $val, wp_salt('nonce')), $sent);
    }

    private static function login_error()
    {
        return sprintf(
            'Usuário ou senha incorretos. Após %d tentativas erradas, o acesso fica bloqueado por %d minutos.',
            Store::MAX_FAILS,
            Store::LOCK_SECONDS / 60
        );
    }

    private static function login()
    {
        if (! self::check_pre_token()) {
            self::render_login('A página de acesso expirou. Tente novamente.', 400);
            return;
        }

        // A tentativa é reservada antes de conferir a senha (contador atômico):
        // tentativas simultâneas não passam todas com o mesmo valor.
        $ip = Store::client_ip();
        if (! Store::ip_reserve($ip)) {
            Store::log_ip_block($ip);
            self::render_login(sprintf('Muitas tentativas a partir desta conexão. Aguarde %d minutos e tente de novo.', Store::IP_WINDOW / 60), 429);
            return;
        }

        // wp_unslash: o WordPress acrescenta barras ao $_POST; sem tirá-las,
        // senhas com aspas ou barras nunca confeririam.
        $login = Store::normalize_login(isset($_POST['login']) && is_string($_POST['login']) ? wp_unslash($_POST['login']) : '');
        $senha = isset($_POST['senha']) && is_string($_POST['senha']) ? wp_unslash($_POST['senha']) : '';

        list($user, $found) = Store::attempt_login($login, $senha);

        if (! $user) {
            // Só registra o login quando a conta existe: evita gravar no
            // registro uma senha digitada por engano no campo de usuário.
            Store::log('login_falha', $found ? '' : 'Usuário inexistente.', $found);
            self::render_login(self::login_error(), 401);
            return;
        }

        Store::ip_release($ip);
        self::set_cookie(self::COOKIE, Store::create_session($user['id']));
        self::clear_cookie(self::COOKIE_PRE);
        Store::log('login', '', $user);

        self::go($user['must_change'] ? ['tela' => 'conta'] : []);
    }

    public static function logout()
    {
        Store::destroy_session(self::$session['id']);
        Store::log('logout', '', self::$user);
        self::clear_cookie(self::COOKIE);
        self::$user    = null;
        self::$session = null;
        self::go(['msg' => 'saiu']);
    }

    /**
     * Troca de sessão: encerra todas as sessões da conta e abre uma nova
     * para este navegador (usada após trocar a senha).
     */
    private static function rotate_session()
    {
        Store::destroy_user_sessions(self::$user['id']);
        self::set_cookie(self::COOKIE, Store::create_session(self::$user['id']));
    }

    public static function end_other_sessions()
    {
        self::rotate_session();
        self::go(
            [
                'tela' => 'conta',
                'msg'  => 'sessoes',
            ]
        );
    }

    public static function change_password()
    {
        $user  = self::$user;
        $field = function ($name) {
            return isset($_POST[$name]) && is_string($_POST[$name]) ? wp_unslash($_POST[$name]) : '';
        };
        $atual = $field('senha_atual');
        $nova  = $field('senha_nova');
        $nova2 = $field('senha_nova2');

        if (! Store::verify_password($atual, $user['pass_hash'])) {
            Store::log('senha_falha', '', $user);
            // Conta como tentativa errada: quem pegou uma sessão aberta não
            // pode ficar testando a senha atual à vontade.
            if (Store::register_failure($user)) {
                Store::destroy_user_sessions($user['id']);
                self::clear_cookie(self::COOKIE);
                self::$user    = null;
                self::$session = null;
                self::render_login(self::login_error(), 401);
                exit;
            }
            self::$conta_error = 'A senha atual não confere.';
            return;
        }

        $problem = Store::password_problem($nova, $user);
        if (! $problem && $nova !== $nova2) {
            $problem = 'A confirmação não é igual à nova senha.';
        }
        if (! $problem && Store::verify_password($nova, $user['pass_hash'])) {
            $problem = 'A nova senha precisa ser diferente da atual.';
        }
        if ($problem) {
            self::$conta_error = $problem;
            return;
        }

        Store::set_password($user['id'], $nova, false); // encerra todas as sessões
        self::set_cookie(self::COOKIE, Store::create_session($user['id']));
        Store::log('senha_alterada', '', $user);

        self::go(
            [
                'tela' => 'conta',
                'msg'  => 'senha',
            ]
        );
    }

    /* ---------------------------------------------------------------------
     * Páginas
     * ------------------------------------------------------------------- */

    private static function asset($path)
    {
        return FESTA_PLUGIN_URL . 'assets/' . $path . '?ver=' . rawurlencode(FESTA_VERSION);
    }

    private static function page_open($title, $current = '')
    {
        self::send_csp();
        $user  = self::$user;
        $menus = [];
        if ($user && ! $user['must_change']) {
            foreach (self::screens() as $tela => $screen) {
                if ('conta' !== $tela && self::can($screen[1])) {
                    $menus[$tela] = [$screen[0], self::url(['tela' => $tela])];
                }
            }
        }
        ?><!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<meta name="robots" content="noindex, nofollow, noarchive" />
<title><?php echo esc_html($title); ?> — Portal Festa dos Amigos</title>
<link rel="stylesheet" href="<?php echo esc_url(self::asset('css/festa-admin.css')); ?>" />
<link rel="stylesheet" href="<?php echo esc_url(self::asset('css/festa-portal.css')); ?>" />
</head>
<body class="festa-portal<?php echo $user ? '' : ' festa-portal--anon'; ?>">
        <?php if ($user) : ?>
<header class="festa-portal-top">
    <a class="festa-portal-brand" href="<?php echo esc_url(self::url()); ?>">
        <strong>Festa dos Amigos 2026</strong>
        <span>Portal de gestão das inscrições</span>
    </a>
    <nav class="festa-portal-nav" aria-label="Seções do portal">
            <?php foreach ($menus as $key => $item) : ?>
        <a href="<?php echo esc_url($item[1]); ?>"<?php echo $current === $key ? ' class="is-current" aria-current="page"' : ''; ?>><?php echo esc_html($item[0]); ?></a>
            <?php endforeach; ?>
    </nav>
    <div class="festa-portal-user">
        <a href="<?php echo esc_url(self::url(['tela' => 'conta'])); ?>"<?php echo 'conta' === $current ? ' class="is-current"' : ''; ?>><?php echo esc_html($user['nome']); ?></a>
        <form method="post" action="<?php echo esc_url(self::url()); ?>">
            <input type="hidden" name="festa_acao" value="sair" />
            <input type="hidden" name="_csrf" value="<?php echo esc_attr(self::csrf_token()); ?>" />
            <button type="submit" class="button button-small">Sair</button>
        </form>
    </div>
</header>
        <?php endif; ?>
<main class="festa-portal-main">
        <?php
    }

    private static function page_close()
    {
        ?>
</main>
<footer class="festa-portal-foot">Secretaria Municipal de Desenvolvimento Social · SMDES — acesso restrito à equipe autorizada.</footer>
<script src="<?php echo esc_url(self::asset('js/festa-admin.js')); ?>"></script>
</body>
</html>
        <?php
    }

    private static function render_login($error = '', $status = 200)
    {
        $pre = self::pre_token(); // define o cookie antes de qualquer saída
        status_header($status);
        $saiu = isset($_GET['msg']) && 'saiu' === $_GET['msg'];

        self::page_open('Entrar');
        ?>
<div class="festa-login">
    <div class="festa-login-card">
        <div class="festa-login-stripe" aria-hidden="true"></div>
        <h1>Festa dos Amigos 2026</h1>
        <p class="festa-login-sub">Portal de gestão das inscrições</p>

        <?php if ($error) : ?>
            <div class="notice notice-error" role="alert"><p><?php echo esc_html($error); ?></p></div>
        <?php elseif ($saiu) : ?>
            <div class="notice notice-success"><p>Você saiu do portal.</p></div>
        <?php endif; ?>

        <form method="post" action="<?php echo esc_url(self::url()); ?>">
            <input type="hidden" name="festa_acao" value="entrar" />
            <input type="hidden" name="_pre" value="<?php echo esc_attr($pre); ?>" />
            <p>
                <label for="festa-login-user">Usuário</label>
                <input type="text" id="festa-login-user" name="login" maxlength="40" required autofocus
                    autocomplete="username" autocapitalize="none" autocorrect="off" spellcheck="false" />
            </p>
            <p>
                <label for="festa-login-pass">Senha</label>
                <input type="password" id="festa-login-pass" name="senha" maxlength="<?php echo (int) Store::MAX_PASSWORD; ?>" required autocomplete="current-password" />
            </p>
            <button type="submit" class="button button-primary button-large">Entrar</button>
        </form>

        <p class="festa-login-hint">Acesso restrito à equipe autorizada. Esqueceu a senha? Peça uma nova ao administrador do site.</p>
    </div>
</div>
        <?php
        self::page_close();
    }

    /**
     * Página de erro. Usada antes de qualquer saída; encerra a requisição.
     */
    public static function stop($message, $code = 403)
    {
        if (! headers_sent()) {
            status_header($code);
        }
        self::page_open('Não foi possível concluir', '');
        ?>
<div class="wrap festa-admin festa-portal-error">
    <h1>Não foi possível concluir</h1>
    <div class="notice notice-error" role="alert"><p><?php echo esc_html($message); ?></p></div>
    <p><a class="button" href="<?php echo esc_url(self::url()); ?>">← Voltar ao portal</a></p>
</div>
        <?php
        self::page_close();
        exit;
    }

    /**
     * Telas do portal: título, permissão exigida ('' = qualquer conta) e a
     * função que a desenha — as mesmas do wp-admin.
     */
    private static function screens()
    {
        return [
            'inscricoes' => ['Inscrições', 'ver', [Panel::class, 'page']],
            'dashboard'  => ['Dashboard', 'dashboard', [Dashboard::class, 'render']],
            'colchoes'   => ['Busca de Colchões', 'colchoes', [AdminDefesa::class, 'render_page']],
            'auditoria'  => ['Auditoria', 'ver', [AuditLog::class, 'render_admin_page']],
            'config'     => ['Configurações', 'config', [Settings::class, 'render']],
            'conta'      => ['Minha conta', '', [self::class, 'screen_conta']],
        ];
    }

    /**
     * Tela pedida no endereço; sem nenhuma, a primeira que a conta pode
     * abrir (a Defesa Civil cai direto na busca de colchões).
     */
    private static function requested_screen()
    {
        if (isset($_GET['tela']) && is_string($_GET['tela'])) {
            return sanitize_key($_GET['tela']);
        }
        foreach (self::screens() as $tela => $screen) {
            if ('' === $screen[1] || self::can($screen[1])) {
                return $tela;
            }
        }
        return 'conta';
    }

    private static function render_screen()
    {
        // Conta com troca de senha pendente só vê "Minha conta" — inclusive
        // na resposta de um POST de troca recusada, que reexibe a tela (o GET
        // já é redirecionado em handle_get()).
        $tela    = self::$user && self::$user['must_change'] ? 'conta' : self::requested_screen();
        $screens = self::screens();
        if (! isset($screens[$tela])) {
            self::stop('Tela não encontrada.', 404);
        }
        list($title, $need, $callback) = $screens[$tela];

        // Permissão conferida antes de qualquer saída.
        if ('' !== $need && ! self::can($need)) {
            Store::log('negado', 'Tela "' . $title . '" — sem a permissão "' . $need . '".', self::$user);
            self::stop('Seu usuário não tem permissão para acessar esta tela.', 403);
        }

        self::page_open($title, $tela);
        call_user_func($callback);
        self::page_close();
    }

    private static function screen_conta()
    {
        $user   = self::$user;
        $labels = Ctx::perms();
        $msg    = isset($_GET['msg']) && is_string($_GET['msg']) ? sanitize_key($_GET['msg']) : '';
        $others = max(0, Store::count_sessions($user['id']) - 1);
        ?>
<div class="wrap festa-admin">
    <h1>Minha conta</h1>

        <?php if (self::$conta_error) : ?>
        <div class="notice notice-error" role="alert"><p><?php echo esc_html(self::$conta_error); ?></p></div>
        <?php elseif ('senha' === $msg) : ?>
        <div class="notice notice-success"><p>Senha alterada. As sessões abertas em outros aparelhos foram encerradas.</p></div>
        <?php elseif ('sessoes' === $msg) : ?>
        <div class="notice notice-success"><p>As outras sessões foram encerradas.</p></div>
        <?php endif; ?>

        <?php if ($user['must_change']) : ?>
        <div class="notice notice-warning"><p><strong>Crie a sua senha para continuar.</strong> A senha que você recebeu é provisória e só serve para este primeiro acesso.</p></div>
        <?php endif; ?>

    <div class="festa-portal-grid">
        <div class="festa-box">
            <h2>Trocar senha</h2>
            <form method="post" action="<?php echo esc_url(self::url(['tela' => 'conta'])); ?>" class="festa-portal-form">
                <input type="hidden" name="festa_acao" value="conta_senha" />
                <input type="hidden" name="_csrf" value="<?php echo esc_attr(self::csrf_token()); ?>" />
                <input type="text" name="login" value="<?php echo esc_attr($user['login']); ?>" autocomplete="username" class="screen-reader-text" tabindex="-1" aria-hidden="true" readonly />
                <p>
                    <label for="festa-senha-atual"><?php echo $user['must_change'] ? 'Senha provisória' : 'Senha atual'; ?></label>
                    <input type="password" id="festa-senha-atual" name="senha_atual" required autocomplete="current-password" maxlength="<?php echo (int) Store::MAX_PASSWORD; ?>" />
                </p>
                <p>
                    <label for="festa-senha-nova">Nova senha</label>
                    <input type="password" id="festa-senha-nova" name="senha_nova" required autocomplete="new-password"
                        minlength="<?php echo (int) Store::MIN_PASSWORD; ?>" maxlength="<?php echo (int) Store::MAX_PASSWORD; ?>" />
                </p>
                <p>
                    <label for="festa-senha-nova2">Repita a nova senha</label>
                    <input type="password" id="festa-senha-nova2" name="senha_nova2" required autocomplete="new-password"
                        minlength="<?php echo (int) Store::MIN_PASSWORD; ?>" maxlength="<?php echo (int) Store::MAX_PASSWORD; ?>" />
                </p>
                <p class="description">Pelo menos <?php echo (int) Store::MIN_PASSWORD; ?> caracteres. Uma frase só sua (três ou quatro palavras) é mais segura e mais fácil de lembrar do que símbolos aleatórios. Não reutilize a senha de outro serviço.</p>
                <button type="submit" class="button button-primary">Salvar nova senha</button>
            </form>
        </div>

        <div class="festa-box">
            <h2>Seus dados</h2>
            <table class="festa-portal-kv">
                <tr><th>Nome</th><td><?php echo esc_html($user['nome']); ?></td></tr>
                <tr><th>Usuário</th><td><code><?php echo esc_html($user['login']); ?></code></td></tr>
                <tr><th>Último acesso</th><td><?php echo esc_html(Store::format_ts($user['last_login'])); ?></td></tr>
                <tr>
                    <th>Você pode</th>
                    <td>
                        <ul class="festa-portal-perms">
                            <?php foreach ($user['perms_list'] as $p) : ?>
                                <li><?php echo esc_html(isset($labels[$p]) ? $labels[$p] : $p); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </td>
                </tr>
            </table>
            <p class="description">Para alterar nome ou permissões, fale com o administrador do site.</p>

            <?php if (! $user['must_change']) : ?>
                <h2 class="festa-portal-h2">Sessões</h2>
                <p>
                    <?php
                    echo esc_html(
                        $others
                            ? sprintf('Além desta, há %d sessão(ões) aberta(s) com a sua conta em outro navegador ou aparelho.', $others)
                            : 'Só esta sessão está aberta.'
                    );
                    ?>
                    A sessão se encerra sozinha após <?php echo (int) (Store::SESSION_IDLE / 60); ?> minutos sem uso.
                </p>
                <?php if ($others) : ?>
                    <form method="post" action="<?php echo esc_url(self::url(['tela' => 'conta'])); ?>">
                        <input type="hidden" name="festa_acao" value="conta_sessoes" />
                        <input type="hidden" name="_csrf" value="<?php echo esc_attr(self::csrf_token()); ?>" />
                        <button type="submit" class="button">Encerrar as outras sessões</button>
                    </form>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>
        <?php
    }
}
