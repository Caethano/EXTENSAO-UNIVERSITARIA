<?php

declare(strict_types=1);

namespace Festa\Portal;

use Festa\Admin\Ctx;
use Festa\Audit\AuditLog;
use Festa\Security\SecurityManager;
use WP_Error;

/**
 * Portal externo — contas, senhas, sessões, bloqueios e registro de atividade.
 *
 * As contas do portal são independentes dos usuários do WordPress: ficam em
 * tabela própria e não dão nenhum acesso ao wp-admin.
 *
 *  - Senhas: bcrypt (custo 12). Nunca guardadas nem registradas em texto.
 *  - Sessões: token aleatório de 256 bits; o banco guarda só o SHA-256 dele,
 *    então uma cópia do banco não permite assumir sessões abertas.
 *  - Datas de controle em timestamp Unix (sem depender de fuso horário).
 */
final class Store
{
    public const MIN_PASSWORD = 12;
    public const MAX_PASSWORD = 256;
    public const BCRYPT_COST  = 12;

    // Tentativas de acesso.
    public const MAX_FAILS    = 5;    // erros seguidos até bloquear a conta…
    public const LOCK_SECONDS = 900;  // …por 15 minutos.
    public const IP_MAX_FAILS = 30;   // erros por endereço IP…
    public const IP_WINDOW    = 900;  // …em 15 minutos.

    // Sessões.
    public const SESSION_IDLE = 3600;  // encerra após 1 h sem uso.
    public const SESSION_MAX  = 43200; // e, de qualquer forma, 12 h após o login.
    public const MAX_SESSIONS = 5;     // por usuário.

    public const LOG_DAYS = 180;

    /**
     * Hash de uma senha aleatória descartada. Usado para gastar o mesmo tempo
     * quando o usuário não existe ou está bloqueado (não dá para descobrir
     * quais logins existem medindo a demora da resposta).
     */
    public const DUMMY_HASH = '$2y$12$wHpap6/I/LVPxHZH.jTzaeTj7bldMBdwelbTpMrkMh9L7VVwfUpvW';

    /* ---------------------------------------------------------------------
     * Tabelas
     * ------------------------------------------------------------------- */

    public static function users_table()
    {
        global $wpdb;
        return $wpdb->prefix . 'festa_portal_users';
    }

    public static function sessions_table()
    {
        global $wpdb;
        return $wpdb->prefix . 'festa_portal_sessions';
    }

    public static function log_table()
    {
        global $wpdb;
        return $wpdb->prefix . 'festa_portal_log';
    }

    /**
     * SQL das tabelas, para o dbDelta (ver create_tables()).
     *
     * @return string[]
     */
    public static function schema($charset)
    {
        $users    = self::users_table();
        $sessions = self::sessions_table();
        $log      = self::log_table();

        return [
            "CREATE TABLE {$users} (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                login VARCHAR(60) NOT NULL DEFAULT '',
                nome VARCHAR(190) NOT NULL DEFAULT '',
                email VARCHAR(190) NOT NULL DEFAULT '',
                pass_hash VARCHAR(255) NOT NULL DEFAULT '',
                perms VARCHAR(255) NOT NULL DEFAULT '',
                ativo TINYINT(1) NOT NULL DEFAULT 1,
                must_change TINYINT(1) NOT NULL DEFAULT 1,
                failed_count INT(10) UNSIGNED NOT NULL DEFAULT 0,
                locked_until BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
                last_login BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
                pass_changed_at BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
                created_at BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY  (id),
                UNIQUE KEY login (login)
            ) {$charset};",
            "CREATE TABLE {$sessions} (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
                token_hash VARCHAR(64) NOT NULL DEFAULT '',
                csrf VARCHAR(64) NOT NULL DEFAULT '',
                ua_hash VARCHAR(64) NOT NULL DEFAULT '',
                ip VARCHAR(45) NOT NULL DEFAULT '',
                created_at BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
                last_seen BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY  (id),
                UNIQUE KEY token_hash (token_hash),
                KEY user_id (user_id)
            ) {$charset};",
            "CREATE TABLE {$log} (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
                login VARCHAR(60) NOT NULL DEFAULT '',
                evento VARCHAR(40) NOT NULL DEFAULT '',
                detalhe VARCHAR(255) NOT NULL DEFAULT '',
                ip VARCHAR(45) NOT NULL DEFAULT '',
                created_at BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY  (id),
                KEY created_at (created_at)
            ) {$charset};",
        ];
    }

    /**
     * Cria ou atualiza as tabelas do portal via dbDelta.
     * Chamado em Plugin::activate() e nas atualizações do plugin.
     */
    public static function create_tables()
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        foreach (self::schema($wpdb->get_charset_collate()) as $sql) {
            dbDelta($sql);
        }
    }

    /* ---------------------------------------------------------------------
     * Utilidades
     * ------------------------------------------------------------------- */

    /**
     * Endereço IP de quem está conectando. Usa só REMOTE_ADDR: cabeçalhos
     * como X-Forwarded-For podem ser forjados por quem faz a requisição.
     * Atrás de um proxy confiável, ajuste pelo filtro festa_portal_client_ip.
     */
    public static function client_ip()
    {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
        $ip = (string) apply_filters('festa_portal_client_ip', $ip);
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
    }

    /**
     * IP gravado nas sessões e no registro de atividade: sempre anonimizado
     * (LGPD), como no log de auditoria. O IP exato só é usado, em memória,
     * para contar tentativas erradas.
     */
    private static function log_ip()
    {
        return AuditLog::anonymize_ip(self::client_ip());
    }

    private static function ua_hash()
    {
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
        return hash('sha256', $ua);
    }

    /**
     * Login válido: 3 a 40 caracteres, minúsculas, números, ponto, hífen e
     * sublinhado, começando por letra ou número. Devolve '' se não servir.
     */
    public static function normalize_login($login)
    {
        $login = strtolower(trim(is_string($login) ? $login : ''));
        return preg_match('/^[a-z0-9][a-z0-9._-]{2,39}$/', $login) ? $login : '';
    }

    /**
     * Lista de permissões válida a partir do que veio do formulário, na
     * ordem de Ctx::perms(). Nenhuma é obrigatória: a conta da Defesa Civil,
     * por exemplo, só confirma colchões e não vê as inscrições.
     */
    public static function clean_perms($perms)
    {
        $sent = array_map('sanitize_key', array_filter((array) $perms, 'is_scalar'));

        return array_values(array_intersect(array_keys(Ctx::perms()), $sent));
    }

    /* ---------------------------------------------------------------------
     * Senhas
     * ------------------------------------------------------------------- */

    /**
     * O bcrypt só considera os primeiros 72 bytes e para no byte nulo.
     * Passar antes por SHA-384 (em base64: 64 caracteres, sem nulos) faz a
     * senha inteira contar, qualquer que seja o tamanho.
     */
    private static function prehash($plain)
    {
        return base64_encode(hash('sha384', (string) $plain, true));
    }

    public static function hash_password($plain)
    {
        return password_hash(self::prehash($plain), PASSWORD_BCRYPT, ['cost' => self::BCRYPT_COST]);
    }

    public static function verify_password($plain, $hash)
    {
        if (! is_string($plain) || strlen($plain) > 1024) {
            $plain = '';
        }
        return password_verify(self::prehash($plain), (string) $hash);
    }

    /**
     * Senha provisória: 16 caracteres sorteados com gerador criptográfico,
     * sem os que se confundem (0/O, 1/l/I), em grupos para facilitar a cópia.
     * Cerca de 93 bits de entropia.
     */
    public static function generate_password()
    {
        $alphabet = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $max      = strlen($alphabet) - 1;
        $groups   = [];
        for ($g = 0; $g < 4; $g++) {
            $part = '';
            for ($i = 0; $i < 4; $i++) {
                $part .= $alphabet[random_int(0, $max)];
            }
            $groups[] = $part;
        }
        return implode('-', $groups);
    }

    /**
     * Motivo pelo qual a senha escolhida não serve ('' = aceita).
     */
    public static function password_problem($plain, $user)
    {
        $plain = (string) $plain;
        $len   = function_exists('mb_strlen') ? mb_strlen($plain, 'UTF-8') : strlen($plain);

        if ($len < self::MIN_PASSWORD) {
            return sprintf('A senha precisa ter pelo menos %d caracteres.', self::MIN_PASSWORD);
        }
        if (strlen($plain) > self::MAX_PASSWORD) {
            return sprintf('A senha é longa demais (máximo de %d caracteres).', self::MAX_PASSWORD);
        }
        $lower = strtolower($plain);
        if (! empty($user['login']) && false !== strpos($lower, strtolower($user['login']))) {
            return 'A senha não pode conter o seu nome de usuário.';
        }
        if (ctype_digit($plain)) {
            return 'A senha não pode ter só números.';
        }
        if (count(array_unique(str_split($lower))) < 6) {
            return 'Use uma senha com mais variedade de caracteres.';
        }
        foreach (['senha', 'password', '123456', 'qwerty', 'festadosamigos'] as $weak) {
            if (0 === strpos($lower, $weak)) {
                return 'Essa senha é fácil de adivinhar. Escolha outra, de preferência uma frase.';
            }
        }
        return '';
    }

    /* ---------------------------------------------------------------------
     * Usuários
     * ------------------------------------------------------------------- */

    private static function hydrate($row)
    {
        if (! $row) {
            return null;
        }
        foreach (['id', 'ativo', 'must_change', 'failed_count', 'locked_until', 'last_login', 'pass_changed_at', 'created_at'] as $int) {
            $row[$int] = (int) $row[$int];
        }
        $row['perms_list'] = self::clean_perms(explode(',', (string) $row['perms']));
        return $row;
    }

    public static function all_users()
    {
        global $wpdb;
        $table = self::users_table();
        $rows  = (array) $wpdb->get_results("SELECT * FROM {$table} ORDER BY nome ASC, id ASC", ARRAY_A);
        return array_map([self::class, 'hydrate'], $rows);
    }

    public static function get_user($id)
    {
        global $wpdb;
        $table = self::users_table();
        return self::hydrate($wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id), ARRAY_A));
    }

    public static function get_user_by_login($login)
    {
        global $wpdb;
        $table = self::users_table();
        return self::hydrate($wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE login = %s", $login), ARRAY_A));
    }

    /**
     * Cria a conta com uma senha provisória (troca obrigatória no 1º acesso).
     *
     * @return array|WP_Error array( 'id' => int, 'senha' => string )
     */
    public static function create_user($nome, $login, $email, $perms)
    {
        global $wpdb;

        $nome  = sanitize_text_field($nome);
        $email = sanitize_email($email);
        $clean = self::normalize_login($login);

        if ('' === $nome) {
            return new WP_Error('festa_nome', 'Informe o nome da pessoa.');
        }
        if ('' === $clean) {
            return new WP_Error('festa_login', 'Usuário inválido: use de 3 a 40 caracteres, só letras minúsculas, números, ponto, hífen ou sublinhado.');
        }
        if (self::get_user_by_login($clean)) {
            return new WP_Error('festa_login', 'Já existe uma conta com esse usuário.');
        }

        if (! self::clean_perms($perms)) {
            return new WP_Error('festa_perms', 'Marque ao menos uma permissão para a conta.');
        }

        $senha = self::generate_password();
        $ok    = $wpdb->insert(
            self::users_table(),
            [
                'login'       => $clean,
                'nome'        => $nome,
                'email'       => $email,
                'pass_hash'   => self::hash_password($senha),
                'perms'       => implode(',', self::clean_perms($perms)),
                'ativo'       => 1,
                'must_change' => 1,
                'created_at'  => time(),
            ]
        );
        if (false === $ok) {
            return new WP_Error('festa_db', 'Não foi possível criar a conta. Tente novamente.');
        }
        return [
            'id'    => (int) $wpdb->insert_id,
            'senha' => $senha,
        ];
    }

    public static function update_user($id, $cols)
    {
        global $wpdb;
        return false !== $wpdb->update(self::users_table(), $cols, ['id' => (int) $id]);
    }

    /**
     * Define a senha e encerra todas as sessões da conta.
     */
    public static function set_password($id, $plain, $must_change)
    {
        self::update_user(
            $id,
            [
                'pass_hash'       => self::hash_password($plain),
                'must_change'     => $must_change ? 1 : 0,
                'pass_changed_at' => time(),
                'failed_count'    => 0,
                'locked_until'    => 0,
            ]
        );
        self::destroy_user_sessions($id);
    }

    public static function delete_user($id)
    {
        global $wpdb;
        self::destroy_user_sessions($id);
        $wpdb->delete(self::users_table(), ['id' => (int) $id]);
    }

    /* ---------------------------------------------------------------------
     * Acesso
     * ------------------------------------------------------------------- */

    /**
     * Confere usuário e senha.
     *
     * A resposta é a mesma (e leva o mesmo tempo) para usuário inexistente,
     * conta desativada, conta bloqueada e senha errada.
     *
     * @return array( array|null $usuario_autenticado, array|null $conta_encontrada )
     */
    public static function attempt_login($login, $senha)
    {
        $user = '' !== $login ? self::get_user_by_login($login) : null;

        if ($user) {
            $user = self::restart_after_lock($user);
        }
        $usable = $user && $user['ativo'] && '' !== $user['pass_hash'] && (int) $user['locked_until'] <= time();

        // A tentativa é reservada ANTES de conferir a senha: o contador sobe
        // numa única instrução SQL, então tentativas simultâneas recebem
        // números diferentes e as que passam do limite nem testam a senha.
        $n = $usable ? self::bump_failures((int) $user['id']) : 0;
        if ($n > self::MAX_FAILS) {
            self::lock($user);
            $usable = false;
        }

        if (! $usable) {
            self::verify_password($senha, self::DUMMY_HASH);
            return [null, $user];
        }

        if (! self::verify_password($senha, $user['pass_hash'])) {
            if ($n >= self::MAX_FAILS) {
                self::lock($user);
            }
            return [null, $user];
        }

        $cols = [
            'failed_count' => 0,
            'locked_until' => 0,
            'last_login'   => time(),
        ];
        if (password_needs_rehash($user['pass_hash'], PASSWORD_BCRYPT, ['cost' => self::BCRYPT_COST])) {
            $cols['pass_hash'] = self::hash_password($senha);
        }
        self::update_user($user['id'], $cols);

        return [self::get_user($user['id']), $user];
    }

    /**
     * Soma 1 aos erros da conta e devolve o valor que ESTA requisição
     * produziu (LAST_INSERT_ID(expr): atômico por conexão).
     */
    private static function bump_failures($id)
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->query($wpdb->prepare(
            'UPDATE ' . self::users_table() . ' SET failed_count = LAST_INSERT_ID(failed_count + 1) WHERE id = %d',
            (int) $id
        ));
        return (int) $wpdb->get_var('SELECT LAST_INSERT_ID()');
    }

    /**
     * Bloqueia a conta por LOCK_SECONDS. O contador não é zerado aqui (só
     * quando o bloqueio vence): zerá-lo no meio de uma rajada deixaria as
     * tentativas seguintes recomeçarem do 1. Registra o bloqueio uma vez.
     */
    private static function lock($user)
    {
        global $wpdb;

        $now = time();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $novo = $wpdb->query($wpdb->prepare(
            'UPDATE ' . self::users_table() . ' SET locked_until = %d WHERE id = %d AND locked_until <= %d',
            $now + self::LOCK_SECONDS,
            (int) $user['id'],
            $now
        ));
        if ($novo) {
            self::log('conta_bloqueada', sprintf('Bloqueada por %d min após %d senhas erradas.', self::LOCK_SECONDS / 60, self::MAX_FAILS), $user);
        }
    }

    /** Bloqueio vencido: a contagem de erros recomeça do zero. */
    private static function restart_after_lock($user)
    {
        global $wpdb;

        $until = (int) $user['locked_until'];
        if ($until === 0 || $until > time()) {
            return $user;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->query($wpdb->prepare(
            'UPDATE ' . self::users_table() . ' SET failed_count = 0, locked_until = 0 WHERE id = %d AND locked_until = %d',
            (int) $user['id'],
            $until
        ));
        $user['failed_count'] = 0;
        $user['locked_until'] = 0;
        return $user;
    }

    /**
     * Conta mais um erro de senha (troca de senha com a senha atual errada);
     * bloqueia a conta ao atingir o limite.
     *
     * @return bool true se a conta ficou bloqueada.
     */
    public static function register_failure($user)
    {
        if (self::bump_failures((int) $user['id']) >= self::MAX_FAILS) {
            self::lock($user);
            return true;
        }
        return false;
    }

    /**
     * Chave do contador de erros por IP. Em IPv6 conta o bloco /64 inteiro:
     * quem recebe um /64 do provedor troca de endereço à vontade, então
     * contar endereço por endereço não limitaria nada. Um IPv4 escrito como
     * IPv6 (::ffff:a.b.c.d, comum em servidores com os dois protocolos) conta
     * pelo próprio IPv4 — sem isso, todos os IPv4 cairiam no mesmo contador.
     */
    private static function ip_key($ip)
    {
        $packed = @inet_pton((string) $ip);
        if (false !== $packed && 16 === strlen($packed)) {
            $ip = str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff")
                ? (string) inet_ntop(substr($packed, 12))
                : 'v6:' . bin2hex(substr($packed, 0, 8));
        }
        return 'portal_ip:' . substr(hash_hmac('sha256', (string) $ip, wp_salt('auth')), 0, 40);
    }

    /**
     * Reserva uma tentativa de login deste IP (contador atômico).
     *
     * @return bool false se o IP passou do limite de tentativas.
     */
    public static function ip_reserve($ip)
    {
        if ('' === $ip) {
            return true;
        }
        return SecurityManager::hit(self::ip_key($ip), self::IP_WINDOW) <= self::IP_MAX_FAILS;
    }

    /** Login certo: devolve a reserva — o limite por IP conta só os erros. */
    public static function ip_release($ip)
    {
        if ('' !== $ip) {
            SecurityManager::undo(self::ip_key($ip));
        }
    }

    /**
     * Registra o bloqueio de um IP uma única vez por janela — um IP barrado
     * que continua insistindo não pode encher o registro de atividade.
     */
    public static function log_ip_block($ip)
    {
        $key = 'festa_pf_logged_' . substr(md5(self::ip_key($ip)), 0, 24);
        if (get_transient($key)) {
            return;
        }
        set_transient($key, 1, self::IP_WINDOW);
        self::log('login_bloqueado', sprintf('Mais de %d tentativas erradas em %d min.', self::IP_MAX_FAILS, self::IP_WINDOW / 60));
    }

    /* ---------------------------------------------------------------------
     * Sessões
     * ------------------------------------------------------------------- */

    /**
     * Abre uma sessão e devolve o token que vai no cookie (só aqui ele
     * existe em claro; no banco fica o hash).
     */
    public static function create_session($user_id)
    {
        global $wpdb;
        $table = self::sessions_table();

        self::purge_sessions();

        // Limite de sessões simultâneas: as mais antigas saem.
        $ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$table} WHERE user_id = %d ORDER BY id DESC", $user_id));
        foreach (array_slice((array) $ids, self::MAX_SESSIONS - 1) as $old) {
            $wpdb->delete($table, ['id' => (int) $old]);
        }

        $token = bin2hex(random_bytes(32));
        $now   = time();
        $wpdb->insert(
            $table,
            [
                'user_id'    => (int) $user_id,
                'token_hash' => hash('sha256', $token),
                'csrf'       => bin2hex(random_bytes(32)),
                'ua_hash'    => self::ua_hash(),
                'ip'         => self::log_ip(),
                'created_at' => $now,
                'last_seen'  => $now,
            ]
        );
        return $token;
    }

    /**
     * Sessão válida para o token do cookie, com a conta dona dela.
     *
     * @return array|null array( 'session' => …, 'user' => … )
     */
    public static function find_session($token)
    {
        global $wpdb;
        if (! is_string($token) || ! preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }
        $table   = self::sessions_table();
        $session = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE token_hash = %s", hash('sha256', $token)), ARRAY_A);
        if (! $session) {
            return null;
        }

        $now = time();
        if ($now - (int) $session['last_seen'] > self::SESSION_IDLE || $now - (int) $session['created_at'] > self::SESSION_MAX) {
            self::destroy_session($session['id']);
            return null;
        }

        $user = self::get_user($session['user_id']);
        if (! $user || ! $user['ativo']) {
            self::destroy_session($session['id']);
            return null;
        }

        // O cookie apareceu em outro navegador: trata como sessão roubada.
        if (! hash_equals((string) $session['ua_hash'], self::ua_hash())) {
            self::destroy_session($session['id']);
            self::log('sessao_recusada', 'Cookie de sessão usado em outro navegador; sessão encerrada.', $user);
            return null;
        }

        if ($now - (int) $session['last_seen'] >= 60) {
            $wpdb->update($table, ['last_seen' => $now], ['id' => (int) $session['id']]);
        }

        return [
            'session' => $session,
            'user'    => $user,
        ];
    }

    public static function destroy_session($id)
    {
        global $wpdb;
        $wpdb->delete(self::sessions_table(), ['id' => (int) $id]);
    }

    public static function destroy_user_sessions($user_id)
    {
        global $wpdb;
        $wpdb->delete(self::sessions_table(), ['user_id' => (int) $user_id]);
    }

    public static function count_sessions($user_id)
    {
        global $wpdb;
        $table = self::sessions_table();
        $min   = time() - self::SESSION_IDLE;
        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND last_seen >= %d", $user_id, $min));
    }

    private static function purge_sessions()
    {
        global $wpdb;
        $table = self::sessions_table();
        $now   = time();
        $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE last_seen < %d OR created_at < %d", $now - self::SESSION_IDLE, $now - self::SESSION_MAX));
    }

    /* ---------------------------------------------------------------------
     * Registro de atividade
     * ------------------------------------------------------------------- */

    public static function log($evento, $detalhe = '', $user = null)
    {
        global $wpdb;
        $table = self::log_table();
        $wpdb->insert(
            $table,
            [
                'user_id'    => $user ? (int) $user['id'] : 0,
                'login'      => $user ? (string) $user['login'] : '',
                'evento'     => substr(sanitize_key($evento), 0, 40),
                'detalhe'    => function_exists('mb_substr') ? mb_substr((string) $detalhe, 0, 250, 'UTF-8') : substr((string) $detalhe, 0, 250),
                'ip'         => self::log_ip(),
                'created_at' => time(),
            ]
        );
        // De vez em quando, descarta o que passou do prazo de guarda.
        if (1 === wp_rand(1, 50)) {
            $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE created_at < %d", time() - self::LOG_DAYS * DAY_IN_SECONDS));
        }
    }

    public static function recent_log($limit = 60)
    {
        global $wpdb;
        $table = self::log_table();
        return (array) $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit), ARRAY_A);
    }

    public static function event_labels()
    {
        return [
            'login'           => 'Entrou',
            'login_falha'     => 'Tentativa de acesso recusada',
            'login_bloqueado' => 'Acesso recusado (muitas tentativas do mesmo IP)',
            'conta_bloqueada' => 'Conta bloqueada',
            'logout'          => 'Saiu',
            'senha_alterada'  => 'Trocou a senha',
            'senha_falha'     => 'Errou a senha atual ao trocar',
            'sessao_recusada' => 'Sessão recusada',
            'negado'          => 'Ação negada (sem permissão)',
            'csrf'            => 'Envio recusado (token inválido)',
            'origem'          => 'Envio recusado (origem inválida)',
            'acao'            => 'Ação',
            'exportacao'      => 'Exportação',
            'admin'           => 'Administração',
        ];
    }

    public static function format_ts($ts, $format = 'd/m/Y H:i')
    {
        return $ts ? wp_date($format, (int) $ts) : '—';
    }
}
