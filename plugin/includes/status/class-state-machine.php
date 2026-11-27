<?php

declare(strict_types=1);

namespace Festa\Status;

use InvalidArgumentException;
use LogicException;
use RuntimeException;

/**
 * Máquina de estados da inscrição.
 *
 * Responsabilidade única: validar transições e disparar o hook
 * `festa_status_transition` para side-effects desacoplados.
 *
 * Grafo de transições válidas:
 *   rascunho → aguardando_analise      (finalização com vaga, ou sem limite)
 *   rascunho → lista_espera            (finalização com as vagas esgotadas)
 *   lista_espera → aguardando_analise  (Comissão chama da fila; exige vaga)
 *   lista_espera → indeferido          (Comissão; justificativa obrigatória)
 *   aguardando_analise → deferido      (exige vaga)
 *   aguardando_analise → indeferido    (Comissão; justificativa obrigatória)
 *   indeferido → aguardando_analise    (Comissão reabre; motivo obrigatório)
 *   deferido → aguardando_comprovantes
 *   aguardando_comprovantes → concluido  (requer flags _tenda_aprovada + _colchoes_confirmados)
 *
 * Qualquer outra transição é bloqueada por LogicException.
 */
final class StateMachine
{
    // -----------------------------------------------------------------------
    // Constantes de status
    // -----------------------------------------------------------------------

    public const STATUS_RASCUNHO      = 'rascunho';
    public const STATUS_ANALISE       = 'aguardando_analise';
    public const STATUS_LISTA_ESPERA  = 'lista_espera';
    public const STATUS_DEFERIDO      = 'deferido';
    public const STATUS_COMPROVANTES  = 'aguardando_comprovantes';
    public const STATUS_CONCLUIDO     = 'concluido';
    public const STATUS_INDEFERIDO    = 'indeferido';

    /**
     * Tamanho mínimo e máximo da justificativa do indeferimento e do motivo
     * da reabertura. O máximo cabe inteiro no detalhe da auditoria (1000
     * caracteres), junto com o rótulo ("Motivo da reabertura: ").
     */
    public const MOTIVO_MIN = 10;
    public const MOTIVO_MAX = 900;

    /**
     * Grafo direcionado de transições permitidas.
     * Chave: status de origem. Valor: lista de destinos válidos.
     *
     * @return array<string, list<string>>
     */
    private static function transition_map(): array
    {
        return [
            self::STATUS_RASCUNHO     => [self::STATUS_ANALISE, self::STATUS_LISTA_ESPERA],
            self::STATUS_LISTA_ESPERA => [self::STATUS_ANALISE, self::STATUS_INDEFERIDO],
            self::STATUS_ANALISE      => [self::STATUS_DEFERIDO, self::STATUS_INDEFERIDO],
            self::STATUS_DEFERIDO     => [self::STATUS_COMPROVANTES],
            self::STATUS_COMPROVANTES => [self::STATUS_CONCLUIDO],
            self::STATUS_CONCLUIDO    => [], // estado terminal
            self::STATUS_INDEFERIDO   => [self::STATUS_ANALISE], // só pela reabertura
        ];
    }

    // -----------------------------------------------------------------------
    // Rótulos
    // -----------------------------------------------------------------------

    /**
     * Status na ordem em que aparecem nas telas e nos relatórios, com o
     * rótulo exibido.
     *
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::STATUS_RASCUNHO     => 'Rascunho',
            self::STATUS_ANALISE      => 'Aguardando Análise',
            self::STATUS_LISTA_ESPERA => 'Lista de Espera',
            self::STATUS_DEFERIDO     => 'Deferido',
            self::STATUS_COMPROVANTES => 'Aguardando Comprovantes',
            self::STATUS_CONCLUIDO    => 'Concluído',
            self::STATUS_INDEFERIDO   => 'Indeferido',
        ];
    }

    public static function label(string $status): string
    {
        return self::labels()[$status] ?? ucfirst(str_replace('_', ' ', $status));
    }

    /** Status atual da inscrição (rascunho quando ainda não há registro). */
    public static function current(int $inscricao_id): string
    {
        $status = (string) get_post_meta($inscricao_id, '_status', true);

        return $status !== '' ? $status : self::STATUS_RASCUNHO;
    }

    /**
     * Status que ocupam uma vaga do limite configurado (ver Vagas).
     *
     * @return list<string>
     */
    public static function ocupam_vaga(): array
    {
        return [self::STATUS_DEFERIDO, self::STATUS_COMPROVANTES, self::STATUS_CONCLUIDO];
    }

    /**
     * A transição exige um texto da Comissão: a justificativa do
     * indeferimento ou o motivo da reabertura.
     */
    public static function exige_motivo(string $de, string $para): bool
    {
        return $para === self::STATUS_INDEFERIDO
            || ($de === self::STATUS_INDEFERIDO && $para === self::STATUS_ANALISE);
    }

    /** O texto tem o tamanho aceito para justificativa/motivo? */
    public static function motivo_valido(string $motivo): bool
    {
        $len = mb_strlen(trim($motivo));

        return $len >= self::MOTIVO_MIN && $len <= self::MOTIVO_MAX;
    }

    // -----------------------------------------------------------------------
    // API pública
    // -----------------------------------------------------------------------

    /**
     * Executa a transição de status de uma inscrição.
     *
     * Quando a transição ocupa ou depende de uma vaga (→ deferido e
     * lista_espera → aguardando_analise), quem chama deve segurar a trava
     * de Vagas::travar() para que duas transições simultâneas não passem
     * do limite.
     *
     * @param int    $inscricao_id ID do CPT inscricao_festa.
     * @param string $novo_status  Um dos STATUS_* desta classe.
     * @param int    $user_id      ID do usuário que originou a ação (0 = sistema/portal/participante).
     * @param string $motivo       Justificativa do indeferimento ou motivo da reabertura
     *                             (texto já sanitizado; obrigatório nessas transições).
     *
     * @throws LogicException   Se a transição for inválida ou faltar a justificativa.
     * @throws RuntimeException Se as pré-condições (vaga, validações) não forem atendidas.
     */
    public static function transition(
        int    $inscricao_id,
        string $novo_status,
        int    $user_id = 0,
        string $motivo = ''
    ): void {
        $map = self::transition_map();

        // Status atual: fallback para rascunho em inscrições recém-criadas
        $status_atual = (string) get_post_meta($inscricao_id, '_status', true);
        if ($status_atual === '') {
            $status_atual = self::STATUS_RASCUNHO;
        }

        // Valida se o status de destino existe no grafo
        if (! array_key_exists($novo_status, $map)) {
            throw new LogicException(
                sprintf(
                    '[Festa\StateMachine] Status desconhecido: "%s".',
                    $novo_status
                )
            );
        }

        // Idempotência: já está no status alvo
        if ($status_atual === $novo_status) {
            return;
        }

        // Valida a aresta no grafo direcionado
        $destinos_validos = $map[$status_atual] ?? [];
        if (! in_array($novo_status, $destinos_validos, true)) {
            throw new LogicException(
                sprintf(
                    '[Festa\StateMachine] Transição inválida: "%s" → "%s". '
                    . 'Destinos permitidos a partir de "%s": [%s].',
                    $status_atual,
                    $novo_status,
                    $status_atual,
                    implode(', ', $destinos_validos) ?: 'nenhum (estado terminal)'
                )
            );
        }

        // Guard clause: justificativa/motivo obrigatório
        $motivo = trim($motivo);
        if (self::exige_motivo($status_atual, $novo_status)) {
            if (! self::motivo_valido($motivo)) {
                throw new InvalidArgumentException(
                    sprintf(
                        '[Festa\StateMachine] Inscrição #%d: informe a justificativa (de %d a %d caracteres).',
                        $inscricao_id,
                        self::MOTIVO_MIN,
                        self::MOTIVO_MAX
                    )
                );
            }
        } else {
            $motivo = '';
        }

        // Guard clause: vaga disponível para deferir ou chamar da lista de espera
        if (
            $novo_status === self::STATUS_DEFERIDO
            || ($status_atual === self::STATUS_LISTA_ESPERA && $novo_status === self::STATUS_ANALISE)
        ) {
            self::assert_vaga($inscricao_id);
        }

        // Guard clause: pré-condições obrigatórias para STATUS_CONCLUIDO
        if ($novo_status === self::STATUS_CONCLUIDO) {
            self::assert_conclusao_preconditions($inscricao_id);
        }

        // Persiste o novo status e os dados da transição
        update_post_meta($inscricao_id, '_status', $novo_status);
        self::persist_transition_data($inscricao_id, $status_atual, $novo_status, $motivo);

        /**
         * Disparado após toda transição de status válida.
         *
         * Listeners: geração de documentos (p10), e-mails (p10) e auditoria (p20).
         *
         * @param int    $inscricao_id
         * @param string $status_anterior
         * @param string $novo_status
         * @param int    $user_id         0 = ação do sistema, do portal ou do participante.
         * @param string $motivo          Justificativa/motivo ('' quando não se aplica).
         */
        do_action(
            'festa_status_transition',
            $inscricao_id,
            $status_atual,
            $novo_status,
            $user_id,
            $motivo
        );
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Metas que acompanham algumas transições:
     *  - lista_espera: `_fila_em` (data/hora da finalização), que define a ordem de chegada;
     *  - indeferido: `_indeferimento_motivo` e `_indeferido_em`;
     *  - reabertura: `_reabertura_motivo` e `_reaberto_em`; a justificativa do
     *    indeferimento deixa de valer (o histórico fica na auditoria).
     */
    private static function persist_transition_data(int $id, string $de, string $para, string $motivo): void
    {
        $agora = current_time('mysql');

        if ($para === self::STATUS_LISTA_ESPERA) {
            update_post_meta($id, '_fila_em', $agora);
        }

        if ($para === self::STATUS_INDEFERIDO) {
            update_post_meta($id, '_indeferimento_motivo', $motivo);
            update_post_meta($id, '_indeferido_em', $agora);
        }

        if ($de === self::STATUS_INDEFERIDO && $para === self::STATUS_ANALISE) {
            update_post_meta($id, '_reabertura_motivo', $motivo);
            update_post_meta($id, '_reaberto_em', $agora);
            delete_post_meta($id, '_indeferimento_motivo');
            delete_post_meta($id, '_indeferido_em');
        }

        // A fila pode ter mudado: posições calculadas antes não valem mais.
        Vagas::esquecer();
    }

    /**
     * Confere que ainda há vaga no limite configurado.
     *
     * @throws RuntimeException Sem vaga restante.
     */
    private static function assert_vaga(int $inscricao_id): void
    {
        if (Vagas::esgotadas()) {
            throw new RuntimeException(
                sprintf(
                    '[Festa\StateMachine] Inscrição #%d: não há vagas restantes (limite de %d vagas atingido).',
                    $inscricao_id,
                    Vagas::limite()
                )
            );
        }
    }

    /**
     * Verifica que ambas as flags de validação dupla estão confirmadas
     * antes de permitir a conclusão da inscrição.
     *
     * @throws RuntimeException Se qualquer pré-condição não for atendida.
     */
    private static function assert_conclusao_preconditions(int $inscricao_id): void
    {
        $tenda_aprovada    = (bool) get_post_meta($inscricao_id, '_tenda_aprovada',       true);
        $colchoes_conf     = (bool) get_post_meta($inscricao_id, '_colchoes_confirmados', true);

        if (! $tenda_aprovada) {
            throw new RuntimeException(
                sprintf(
                    '[Festa\StateMachine] Inscrição #%d: recibo da tenda ainda não aprovado '
                    . '(_tenda_aprovada = false). Conclusão bloqueada.',
                    $inscricao_id
                )
            );
        }

        if (! $colchoes_conf) {
            throw new RuntimeException(
                sprintf(
                    '[Festa\StateMachine] Inscrição #%d: doação de colchões não confirmada '
                    . '(_colchoes_confirmados = false). Conclusão bloqueada.',
                    $inscricao_id
                )
            );
        }
    }
}
