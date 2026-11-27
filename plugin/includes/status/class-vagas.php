<?php

declare(strict_types=1);

namespace Festa\Status;

use Festa\Cpt\CptInscricao;
use Festa\Security\SecurityManager;
use Festa\Settings\Settings;

/**
 * Limite de vagas e lista de espera.
 *
 *  - Limite: Configurações → "Limite de vagas" (0 = sem limite).
 *  - Vagas ocupadas: inscrições em deferido, aguardando_comprovantes ou concluido.
 *  - Vagas restantes: limite − ocupadas, nunca negativo.
 *  - Lista de espera: inscrições em lista_espera, na ordem de `_fila_em`
 *    (data/hora da finalização); empate pelo protocolo.
 *
 * As contagens vão direto ao banco (sem cache): uma decisão sobre vaga não
 * pode usar um número lido antes de outra requisição mudar o status.
 *
 * Duas ações simultâneas que dependem de vaga (deferir, chamar da fila) — e
 * as que disputam as mesmas inscrições (indeferir, reabrir, envio da ficha)
 * — são serializadas pela trava travar()/destravar(), uma trava exclusiva
 * do banco (GET_LOCK, via SecurityManager): a segunda espera a primeira
 * terminar e só então confere de novo o status e as vagas.
 */
final class Vagas
{
    /** Nome da trava das ações que dependem de vaga. */
    private const TRAVA = 'festa_vagas';

    /** Quanto tempo uma ação espera a trava antes de desistir (segundos). */
    private const ESPERA = 15;

    private static bool $travado = false;

    /** @var array<int, int>|null Posição de cada inscrição na fila (cache da requisição). */
    private static ?array $posicoes = null;

    // -----------------------------------------------------------------------
    // Limite e contagens
    // -----------------------------------------------------------------------

    /** Limite configurado (0 = sem limite). */
    public static function limite(): int
    {
        return max(0, (int) Settings::get('limite_vagas'));
    }

    public static function tem_limite(): bool
    {
        return self::limite() > 0;
    }

    /** Inscrições que ocupam vaga: deferido + aguardando_comprovantes + concluido. */
    public static function ocupadas(): int
    {
        global $wpdb;

        $status = StateMachine::ocupam_vaga();
        $marks  = implode(', ', array_fill(0, count($status), '%s'));

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*)
               FROM {$wpdb->posts} p
         INNER JOIN {$wpdb->postmeta} st ON st.post_id = p.ID AND st.meta_key = '_status'
              WHERE p.post_type = %s AND p.post_status = 'publish' AND st.meta_value IN ({$marks})",
            CptInscricao::POST_TYPE,
            ...$status
        ));
    }

    /** Vagas restantes (null = sem limite). Nunca negativo. */
    public static function restantes(): ?int
    {
        $limite = self::limite();

        return $limite > 0 ? max(0, $limite - self::ocupadas()) : null;
    }

    /** Há limite e nenhuma vaga restante? */
    public static function esgotadas(): bool
    {
        return self::restantes() === 0;
    }

    /**
     * Status de uma inscrição que acabou de ser finalizada: com as vagas
     * esgotadas, ela entra na lista de espera.
     */
    public static function status_finalizacao(): string
    {
        return self::esgotadas() ? StateMachine::STATUS_LISTA_ESPERA : StateMachine::STATUS_ANALISE;
    }

    // -----------------------------------------------------------------------
    // Lista de espera
    // -----------------------------------------------------------------------

    /**
     * IDs na lista de espera, do primeiro ao último.
     *
     * @return list<int>
     */
    public static function fila(): array
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT p.ID
               FROM {$wpdb->posts} p
         INNER JOIN {$wpdb->postmeta} st ON st.post_id = p.ID AND st.meta_key = '_status'
          LEFT JOIN {$wpdb->postmeta} f ON f.post_id = p.ID AND f.meta_key = '_fila_em'
              WHERE p.post_type = %s AND p.post_status = 'publish' AND st.meta_value = %s
           ORDER BY (f.meta_value IS NULL) ASC, f.meta_value ASC, p.ID ASC",
            CptInscricao::POST_TYPE,
            StateMachine::STATUS_LISTA_ESPERA
        ));

        return array_map('intval', (array) $ids);
    }

    /** Primeiro da fila (0 = fila vazia). */
    public static function proximo(): int
    {
        return self::fila()[0] ?? 0;
    }

    /** Posição da inscrição na fila, a partir de 1 (0 = não está na fila). */
    public static function posicao(int $inscricao_id): int
    {
        if (self::$posicoes === null) {
            self::$posicoes = [];
            foreach (self::fila() as $i => $id) {
                self::$posicoes[$id] = $i + 1;
            }
        }

        return self::$posicoes[$inscricao_id] ?? 0;
    }

    /** Esquece as posições calculadas (depois de mudar a fila nesta requisição). */
    public static function esquecer(): void
    {
        self::$posicoes = null;
    }

    // -----------------------------------------------------------------------
    // Trava
    // -----------------------------------------------------------------------

    /**
     * Pega a trava das ações que dependem de vaga, esperando até ESPERA
     * segundos se outra ação estiver com ela. É a trava do banco
     * (SecurityManager::db_lock): liberada em destravar() ou, se a requisição
     * terminar antes, pelo próprio banco ao fechar a conexão.
     *
     * Enquanto a trava está presa, os e-mails das transições ficam
     * guardados (hook festa_vagas_travadas) e só saem depois de liberada
     * (festa_vagas_destravadas): um servidor de e-mail lento não segura a
     * fila das outras ações.
     */
    public static function travar(): bool
    {
        if (self::$travado) {
            return true;
        }
        if (! SecurityManager::db_lock(self::TRAVA, self::ESPERA)) {
            return false;
        }

        self::$travado = true;
        static $registrado = false;
        if (! $registrado) {
            $registrado = true;
            register_shutdown_function([self::class, 'destravar']);
        }
        do_action('festa_vagas_travadas');

        return true;
    }

    public static function destravar(): void
    {
        if (self::$travado) {
            SecurityManager::db_unlock(self::TRAVA);
            self::$travado = false;
            do_action('festa_vagas_destravadas');
        }
    }
}
