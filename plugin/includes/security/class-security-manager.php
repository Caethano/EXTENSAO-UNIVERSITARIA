<?php

declare(strict_types=1);

namespace Festa\Security;

use Festa\Upload\Handler;

/**
 * SecurityManager — Hardening e controle de taxa.
 *
 * Responsabilidades:
 *  1. Forçar o estado correto das proteções do diretório de uploads privados.
 *  2. Contadores atômicos para limites de tentativas (login, pré-cadastro).
 *  3. Aplicar rate limiting por IP anonimizado para endpoints sensíveis.
 *  4. Travas curtas contra envios simultâneos (ex.: o mesmo CPF duas vezes).
 *
 * Esta classe não registra hooks — é invocada diretamente nos pontos críticos:
 *  - `enforce_htaccess()` e `create_table()` → Plugin::install()
 *  - `check_rate_limit()` → Frontend\AjaxHandler::ini_inscricao()
 *  - `hit()` / `undo()` / `clear()` → AuthManager, Portal\Store e as ações de status da Comissão
 *  - `acquire_lock()` → pré-cadastro; `db_lock()` / `db_unlock()` → Status\Vagas
 */
final class SecurityManager
{
    /** Máximo de pré-cadastros permitidos na janela de tempo, por rede. */
    private const RATE_LIMIT_MAX = 10;

    /** Janela de tempo em segundos (10 minutos). */
    private const RATE_LIMIT_TTL = 600;

    /** Uma trava mais velha que isto é de uma requisição que não terminou. */
    private const LOCK_STALE = 60;

    // -----------------------------------------------------------------------
    // enforce_htaccess
    // -----------------------------------------------------------------------

    /**
     * Garante que o diretório de uploads privados existe e está protegido.
     *
     * As regras são SOBRESCRITAS sempre — garantindo que nenhuma atualização
     * manual acidental enfraqueça o bloqueio. Cobrem Apache 2.2 e 2.4
     * (.htaccess) e IIS (web.config); o Nginx deve ser configurado
     * separadamente via server block quando o diretório ficar dentro da
     * raiz do site.
     *
     * Invocado em Plugin::activate() e a cada atualização do plugin.
     */
    public static function enforce_htaccess(): void
    {
        Handler::protect_dir();
    }

    // -----------------------------------------------------------------------
    // Contadores atômicos
    // -----------------------------------------------------------------------

    public static function counters_table(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'festa_counters';
    }

    public static function create_table(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta('CREATE TABLE ' . self::counters_table() . " (
            k VARCHAR(100) NOT NULL,
            n INT(10) UNSIGNED NOT NULL DEFAULT 0,
            expires BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY  (k),
            KEY expires (expires)
        ) {$wpdb->get_charset_collate()};");
    }

    /**
     * Soma 1 ao contador e devolve o valor que ESTA requisição produziu.
     *
     * Uma única instrução SQL (INSERT … ON DUPLICATE KEY UPDATE) com
     * LAST_INSERT_ID(expr): requisições simultâneas recebem valores
     * diferentes (1, 2, 3…) — ler, conferir e gravar depois deixava várias
     * delas passarem com o mesmo valor. A janela começa no primeiro registro
     * e não é renovada pelos seguintes.
     */
    public static function hit(string $key, int $window): int
    {
        global $wpdb;

        $table = self::counters_table();
        $now   = time();

        // phpcs:disable WordPress.DB.DirectDatabaseQuery
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$table} (k, n, expires) VALUES (%s, LAST_INSERT_ID(1), %d)
             ON DUPLICATE KEY UPDATE
                n       = LAST_INSERT_ID(IF(expires <= %d, 1, n + 1)),
                expires = IF(expires <= %d, %d, expires)",
            $key,
            $now + $window,
            $now,
            $now,
            $now + $window
        ));
        $n = (int) $wpdb->get_var('SELECT LAST_INSERT_ID()');

        // Faxina ocasional dos contadores vencidos.
        if (random_int(1, 100) === 1) {
            $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE expires <= %d", $now));
        }
        // phpcs:enable

        return $n;
    }

    /** Valor atual do contador (0 se vencido ou inexistente). */
    public static function peek(string $key): int
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT n FROM ' . self::counters_table() . ' WHERE k = %s AND expires > %d',
            $key,
            time()
        ));
    }

    /** Desfaz uma unidade (tentativa reservada que deu certo). */
    public static function undo(string $key): void
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->query($wpdb->prepare(
            'UPDATE ' . self::counters_table() . ' SET n = IF(n > 0, n - 1, 0) WHERE k = %s',
            $key
        ));
    }

    public static function clear(string $key): void
    {
        global $wpdb;

        $wpdb->delete(self::counters_table(), ['k' => $key]);
    }

    /** Apaga todos os contadores cuja chave começa com $prefix. */
    public static function clear_prefix(string $prefix): void
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->query($wpdb->prepare(
            'DELETE FROM ' . self::counters_table() . ' WHERE k LIKE %s',
            $wpdb->esc_like($prefix) . '%'
        ));
    }

    // -----------------------------------------------------------------------
    // check_rate_limit
    // -----------------------------------------------------------------------

    /**
     * Conta mais um pré-cadastro da rede informada e recusa acima do limite.
     *
     * A chave é derivada do IP já anonimizado — o IP exato nunca é
     * armazenado, mesmo temporariamente. A janela é fixa: começa na primeira
     * tentativa e não é renovada pelas seguintes, para que uma rede
     * compartilhada (repartição, operadora de celular) não fique barrada
     * indefinidamente.
     *
     * @param string $ip_anonimizado IP já processado por AuditLog::anonymize_ip()
     *                                ou equivalente. Ex: "192.168.1.0".
     * @throws \RuntimeException Se o número de tentativas exceder RATE_LIMIT_MAX
     *                           na janela de RATE_LIMIT_TTL segundos.
     */
    public static function check_rate_limit(string $ip_anonimizado): void
    {
        if (self::hit('rl:' . md5($ip_anonimizado), self::RATE_LIMIT_TTL) > self::RATE_LIMIT_MAX) {
            throw new \RuntimeException(
                sprintf(
                    'Limite de tentativas excedido (%d por %d segundos). Tente novamente em instantes.',
                    self::RATE_LIMIT_MAX,
                    self::RATE_LIMIT_TTL
                )
            );
        }
    }

    // -----------------------------------------------------------------------
    // Travas
    // -----------------------------------------------------------------------

    private static function lock_key(string $name): string
    {
        return 'festa_lock_' . substr(hash('sha256', $name), 0, 40);
    }

    /**
     * Tenta pegar uma trava exclusiva. O INSERT IGNORE numa coluna de chave
     * única é atômico: de dois envios simultâneos, só um consegue.
     */
    public static function acquire_lock(string $name): bool
    {
        global $wpdb;

        $key = self::lock_key($name);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $inserted = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')",
            $key,
            (string) time()
        ));

        if ($inserted) {
            return true;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $since = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
            $key
        ));

        // Trava abandonada: só UMA requisição a assume — a que trocar o valor
        // antigo pelo novo (UPDATE condicional; as outras afetam 0 linhas).
        if ($since > 0 && time() - $since > self::LOCK_STALE) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            $taken = $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
                (string) time(),
                $key,
                (string) $since
            ));

            return $taken === 1;
        }

        return false;
    }

    // -----------------------------------------------------------------------
    // Trava do banco (GET_LOCK)
    // -----------------------------------------------------------------------

    private static function db_lock_name(string $name): string
    {
        global $wpdb;

        // Única por banco + prefixo (dois sites no mesmo servidor não se travam); até 64 caracteres.
        return 'festa_' . md5(DB_NAME . '|' . $wpdb->prefix . '|' . $name);
    }

    /**
     * Trava exclusiva do MySQL/MariaDB (GET_LOCK), esperando até $timeout
     * segundos. Ela pertence à conexão desta requisição: se o processo
     * terminar de qualquer jeito (erro, exit, processo encerrado), o banco a
     * libera sozinho — não existe trava "abandonada" para alguém assumir.
     * Usada onde a segunda requisição deve esperar a primeira terminar e
     * então decidir com o estado novo (ex.: dois deferimentos disputando a
     * última vaga).
     */
    public static function db_lock(string $name, int $timeout): bool
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        return (string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', self::db_lock_name($name), max(0, $timeout))) === '1';
    }

    public static function db_unlock(string $name): void
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', self::db_lock_name($name)));
    }

    public static function release_lock(string $name): void
    {
        global $wpdb;

        $wpdb->delete($wpdb->options, ['option_name' => self::lock_key($name)]);
    }
}
