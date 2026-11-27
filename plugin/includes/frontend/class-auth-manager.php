<?php

declare(strict_types=1);

namespace Festa\Frontend;

use Festa\Cpt\CptInscricao;
use Festa\Crypto\Crypto;
use Festa\Pages\Pages;
use Festa\Security\SecurityManager;

/**
 * Gerenciamento de sessão silo para participantes.
 *
 * Participantes NÃO são usuários do WordPress. A identidade é resolvida
 * exclusivamente por um token seguro armazenado em transient (servidor) e
 * em cookie HttpOnly (cliente), mapeando para um post_id de inscricao_festa.
 *
 * Mecanismo:
 *   login($id)  → gera token aleatório → set_transient + setcookie
 *   get_current_inscricao_id() → lê cookie → valida transient → retorna int|null
 *   logout()    → delete_transient + expira cookie
 *
 * O servidor guarda só o SHA-256 do token (uma cópia do banco não permite
 * assumir sessões abertas) e a sessão fica presa ao navegador que a abriu.
 *
 * Senhas: a do pré-cadastro é sorteada pelo sistema e mostrada uma única vez;
 * o participante pode trocá-la no painel, e a Comissão pode gerar outra.
 */
final class AuthManager
{
    private const COOKIE_NAME      = 'festa_auth_token';
    private const TRANSIENT_PREFIX = 'festa_sess_';
    private const TTL              = 2 * HOUR_IN_SECONDS; // 7200 segundos

    public const MIN_PASSWORD = 8;
    public const MAX_PASSWORD = 128;

    // Tentativas de acesso.
    public const MAX_FAILS       = 5;                     // erros numa inscrição a partir da mesma rede…
    public const LOCK_SECONDS    = 15 * MINUTE_IN_SECONDS; // …bloqueiam essa rede por 15 minutos.
    public const MAX_FAILS_TOTAL = 50;                    // erros numa inscrição somando todas as redes.
    public const IP_MAX_FAILS    = 30;                    // erros por rede (IP anonimizado)…
    public const IP_WINDOW       = 15 * MINUTE_IN_SECONDS; // …em 15 minutos.

    /** Meta que muda a cada troca de senha: sessões abertas antes deixam de valer. */
    private const SESSION_GEN_META = '_sessao_geracao';

    /** Sessão já resolvida nesta requisição (false = ainda não consultada). */
    private static array|null|false $session = false;

    // -----------------------------------------------------------------------
    // Sessão
    // -----------------------------------------------------------------------

    /**
     * Inicia uma sessão autenticada para a inscrição informada.
     * Gera um token de 256 bits (64 hex chars), persiste em transient
     * e entrega ao navegador via cookie HttpOnly. A sessão anterior deste
     * navegador, se houver, é apagada.
     *
     * @param string $csrf Token CSRF a manter (troca de senha: a página aberta
     *                     continua enviando o mesmo); '' sorteia um novo.
     */
    public static function login(int $inscricao_id, string $csrf = ''): void
    {
        $anterior = self::token();
        if ($anterior !== '') {
            delete_transient(self::key($anterior));
        }

        // Token CSPRNG: bin2hex(random_bytes(32)) → 64 caracteres hexadecimais.
        $token   = bin2hex(random_bytes(32));
        $session = [
            'id'   => $inscricao_id,
            'ua'   => self::ua_hash(),
            'csrf' => $csrf !== '' ? $csrf : bin2hex(random_bytes(32)),
            'gen'  => self::generation($inscricao_id),
        ];

        // Servidor: mapeia hash(token) → sessão com TTL de 2 horas.
        set_transient(self::key($token), $session, self::TTL);

        // Cliente: cookie HttpOnly, SameSite=Lax, Secure se HTTPS.
        self::set_cookie($token, time() + self::TTL);

        // Disponibiliza na requisição atual sem aguardar o navegador reenviar.
        $_COOKIE[self::COOKIE_NAME] = $token;
        self::$session              = $session;
    }

    /**
     * Encerra a sessão: invalida o transient no servidor e expira o cookie.
     */
    public static function logout(): void
    {
        $token = self::token();

        if ($token !== '') {
            delete_transient(self::key($token));
        }

        self::set_cookie('', time() - HOUR_IN_SECONDS);

        unset($_COOKIE[self::COOKIE_NAME]);
        self::$session = null;
    }

    /**
     * Retorna o post_id da inscrição autenticada, ou null se não há sessão válida.
     */
    public static function get_current_inscricao_id(): ?int
    {
        $session = self::session();

        return $session ? (int) $session['id'] : null;
    }

    /**
     * Token que os formulários do participante devolvem para provar que o
     * envio partiu desta sessão ('' sem sessão).
     */
    public static function csrf_token(): string
    {
        $session = self::session();

        return $session ? (string) $session['csrf'] : '';
    }

    public static function check_csrf(string $sent): bool
    {
        $token = self::csrf_token();

        return $token !== '' && hash_equals($token, $sent);
    }

    /** @return array{id: int, ua: string, csrf: string}|null */
    private static function session(): ?array
    {
        if (self::$session !== false) {
            return self::$session;
        }

        self::$session = null;
        $token         = self::token();

        if ($token === '') {
            return null;
        }

        $data = get_transient(self::key($token));
        if (! is_array($data) || (int) ($data['id'] ?? 0) <= 0) {
            return null;
        }

        // O cookie apareceu em outro navegador: trata como sessão roubada.
        if (! hash_equals((string) ($data['ua'] ?? ''), self::ua_hash())) {
            delete_transient(self::key($token));
            return null;
        }

        // A inscrição foi excluída depois do login.
        if (get_post_type((int) $data['id']) !== CptInscricao::POST_TYPE) {
            delete_transient(self::key($token));
            return null;
        }

        // A senha foi trocada depois do login (pelo participante ou pela equipe).
        if ((int) ($data['gen'] ?? 0) !== self::generation((int) $data['id'])) {
            delete_transient(self::key($token));
            return null;
        }

        return self::$session = [
            'id'   => (int) $data['id'],
            'ua'   => (string) $data['ua'],
            'csrf' => (string) ($data['csrf'] ?? ''),
        ];
    }

    /** Token do cookie, só se tiver o formato esperado. */
    private static function token(): string
    {
        $token = $_COOKIE[self::COOKIE_NAME] ?? '';

        return is_string($token) && preg_match('/^[a-f0-9]{64}$/', $token) ? $token : '';
    }

    private static function key(string $token): string
    {
        return self::TRANSIENT_PREFIX . hash('sha256', $token);
    }

    private static function generation(int $inscricao_id): int
    {
        return (int) get_post_meta($inscricao_id, self::SESSION_GEN_META, true);
    }

    private static function ua_hash(): string
    {
        return hash('sha256', isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : '');
    }

    private static function set_cookie(string $value, int $expires): void
    {
        if (headers_sent()) {
            return;
        }

        setcookie(self::COOKIE_NAME, $value, [
            'expires'  => $expires,
            'path'     => '/',
            'domain'   => '',
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    // -----------------------------------------------------------------------
    // Aviso de uma única exibição (envio sem JavaScript)
    // -----------------------------------------------------------------------

    /**
     * Guarda um valor para ser mostrado uma vez na próxima página desta
     * sessão. Vai cifrado: é uma senha, e o banco não pode tê-la em claro.
     */
    public static function flash_set(string $name, string $value): void
    {
        $token = self::token();
        if ($token !== '') {
            set_transient('festa_flash_' . $name . '_' . hash('sha256', $token), Crypto::encrypt($value), 10 * MINUTE_IN_SECONDS);
        }
    }

    public static function flash_get(string $name): string
    {
        $token = self::token();
        if ($token === '') {
            return '';
        }

        $key   = 'festa_flash_' . $name . '_' . hash('sha256', $token);
        $value = get_transient($key);
        if ($value === false) {
            return '';
        }
        delete_transient($key);

        try {
            return Crypto::decrypt((string) $value);
        } catch (\Throwable) {
            return '';
        }
    }

    // -----------------------------------------------------------------------
    // Senhas
    // -----------------------------------------------------------------------

    /**
     * Senha provisória: 12 caracteres sorteados com gerador criptográfico,
     * sem os que se confundem (0/O, 1/l/I), em grupos para facilitar a cópia.
     * Cerca de 69 bits de entropia.
     */
    public static function generate_password(): string
    {
        $alphabet = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $max      = strlen($alphabet) - 1;
        $groups   = [];

        for ($g = 0; $g < 3; $g++) {
            $part = '';
            for ($i = 0; $i < 4; $i++) {
                $part .= $alphabet[random_int(0, $max)];
            }
            $groups[] = $part;
        }

        return implode('-', $groups);
    }

    /**
     * Grava a senha da inscrição (só o hash) e encerra todas as sessões
     * abertas com a senha anterior.
     *
     * @param bool $provisional true quando foi sorteada pelo sistema.
     */
    public static function set_password(int $inscricao_id, string $plain, bool $provisional): void
    {
        update_post_meta($inscricao_id, '_senha_hash', wp_hash_password($plain));
        update_post_meta($inscricao_id, '_senha_provisoria', $provisional ? 1 : 0);
        update_post_meta($inscricao_id, self::SESSION_GEN_META, self::generation($inscricao_id) + 1);
    }

    /**
     * Confere a senha. Senhas mais curtas que o mínimo nunca entram.
     */
    public static function verify_password(int $inscricao_id, string $plain): bool
    {
        $hash = (string) get_post_meta($inscricao_id, '_senha_hash', true);

        return $hash !== ''
            && mb_strlen($plain, 'UTF-8') >= self::MIN_PASSWORD
            && strlen($plain) <= 1024
            && wp_check_password($plain, $hash);
    }

    /**
     * Motivo pelo qual a senha escolhida não serve ('' = aceita).
     */
    public static function password_problem(string $plain, string $cpf_digits = ''): string
    {
        $len = mb_strlen($plain, 'UTF-8');

        if ($len < self::MIN_PASSWORD) {
            return sprintf('A senha precisa ter pelo menos %d caracteres.', self::MIN_PASSWORD);
        }
        if (strlen($plain) > self::MAX_PASSWORD) {
            return sprintf('A senha é longa demais (máximo de %d caracteres).', self::MAX_PASSWORD);
        }

        $lower = strtolower($plain);
        if ($cpf_digits !== '' && str_contains((string) preg_replace('/\D/', '', $plain), $cpf_digits)) {
            return 'A senha não pode ser o seu CPF.';
        }
        if (count(array_unique(str_split($lower))) < 4) {
            return 'Use uma senha com mais variedade de caracteres.';
        }
        foreach (['12345678', 'senha', 'password', 'qwerty', 'festadosamigos'] as $weak) {
            if (str_starts_with($lower, $weak)) {
                return 'Essa senha é fácil de adivinhar. Escolha outra.';
            }
        }

        return '';
    }

    // -----------------------------------------------------------------------
    // Bloqueio por tentativas erradas
    //
    // Cada tentativa é RESERVADA antes de conferir a senha, com contadores
    // atômicos (SecurityManager::hit): tentativas simultâneas recebem números
    // diferentes e as excedentes nem chegam a testar a senha. Quando a senha
    // confere, a reserva é devolvida.
    //
    // O bloqueio vale para a inscrição a partir de uma rede (IP anonimizado):
    // quem erra a senha de outra pessoa tranca só a própria rede, não o dono.
    // Um teto bem maior, somando todas as redes, segura ataques distribuídos.
    // -----------------------------------------------------------------------

    private static function cpf_prefix(string $cpf_hash): string
    {
        return 'pcpf:' . substr($cpf_hash, 0, 40);
    }

    /**
     * Reserva uma tentativa de senha.
     *
     * @return string '' = pode tentar; 'ip' = a rede passou do limite;
     *                'cpf' = a inscrição está bloqueada para esta rede.
     */
    public static function reserve_attempt(string $cpf_hash, string $rede): string
    {
        $rede_key = substr(hash('sha256', $rede), 0, 16);
        $ip       = SecurityManager::hit('pip:' . $rede_key, self::IP_WINDOW);
        $na_rede  = SecurityManager::hit(self::cpf_prefix($cpf_hash) . ':' . $rede_key, self::LOCK_SECONDS);
        $total    = SecurityManager::hit(self::cpf_prefix($cpf_hash), self::LOCK_SECONDS);

        if ($ip > self::IP_MAX_FAILS) {
            return 'ip';
        }

        return $na_rede > self::MAX_FAILS || $total > self::MAX_FAILS_TOTAL ? 'cpf' : '';
    }

    /** A senha conferiu: zera os erros desta rede e devolve a reserva. */
    public static function attempt_succeeded(string $cpf_hash, string $rede): void
    {
        $rede_key = substr(hash('sha256', $rede), 0, 16);
        SecurityManager::clear(self::cpf_prefix($cpf_hash) . ':' . $rede_key);
        SecurityManager::undo(self::cpf_prefix($cpf_hash));
        SecurityManager::undo('pip:' . $rede_key);
    }

    /** Destrava a inscrição em todas as redes (a equipe gerou uma senha nova). */
    public static function clear_failures(string $cpf_hash): void
    {
        SecurityManager::clear_prefix(self::cpf_prefix($cpf_hash));
    }

    // -----------------------------------------------------------------------
    // UI compartilhada
    // -----------------------------------------------------------------------

    /**
     * Renderiza o formulário de login (CPF + Senha) compartilhado entre
     * [festa_inscricao] e [festa_painel]. Após login bem-sucedido, a página
     * é recarregada para renderizar o estado correto.
     */
    public static function render_login_form(): string
    {
        return Ui::card_open('Acessar inscrição', 'Informe o CPF do responsável e a senha recebida no pré-cadastro.')
            . Ui::form_open('festa_auth_login')
            . Ui::field_text('cpf', 'CPF', ['type' => 'cpf', 'placeholder' => '000.000.000-00'])
            . Ui::field_text('senha', 'Senha', ['type' => 'password', 'attrs' => 'autocomplete="current-password"'])
            . Ui::form_close('Entrar')
            . '<p class="festa-login-hint">Ainda não se cadastrou? '
            . '<a href="' . esc_url(Pages::url('pre')) . '">Faça o pré-cadastro</a>.<br>'
            . 'Esqueceu a senha? Procure a Comissão Organizadora (SMDES) para receber uma nova.</p>'
            . '</div>';
    }
}
