<?php

declare(strict_types=1);

namespace Festa\Admin;

use Festa\Audit\AuditLog;
use Festa\Cpt\CptInscricao;
use Festa\Security\SecurityManager;
use Festa\Status\StateMachine;
use Festa\Status\Vagas;
use Festa\Upload\Handler;

/**
 * Ações da Comissão.
 *
 * Renderiza a caixa "Ações da Comissão" na tela da inscrição com a ação da
 * fase em que ela está:
 *  - Upload do documento de deferimento ou indeferimento com justificativa (Fase 2).
 *  - Lista de espera: posição e indeferimento.
 *  - Indeferida: justificativa e reabertura com motivo.
 *  - Número da tenda sorteada (Fase 3).
 *  - Botão "Aprovar Recibo da Tenda" (Fase 4A).
 *
 * Ações (POST, com permissão e nonce/sessão conferidos pelo Ctx):
 *  - festa_deferir      (exige vaga; serializado pela trava de Vagas)
 *  - festa_indeferir    (justificativa obrigatória)
 *  - festa_reabrir      (motivo obrigatório)
 *  - festa_chamar_fila  (primeiro da lista de espera → análise; exige vaga)
 *  - festa_tenda
 *  - festa_recibo
 *
 * Toda mudança de status passa pela máquina de estados.
 */
final class AdminActions
{
    public static function boot_hooks(): void
    {
        add_action('admin_post_festa_deferir',     [self::class, 'deferir']);
        add_action('admin_post_festa_indeferir',   [self::class, 'indeferir']);
        add_action('admin_post_festa_reabrir',     [self::class, 'reabrir']);
        add_action('admin_post_festa_chamar_fila', [self::class, 'chamar_fila']);
        add_action('admin_post_festa_tenda',       [self::class, 'tenda']);
        add_action('admin_post_festa_recibo',      [self::class, 'aprovar_recibo']);
    }

    /**
     * Ações de status que avisam o participante por e-mail (indeferir,
     * reabrir, chamar da fila): no máximo RATE_MAX por usuário a cada
     * RATE_WINDOW segundos, para que um clique repetido ou um script não
     * encha a caixa de ninguém. Além disso, indeferir e reabrir a MESMA
     * inscrição somam no máximo RATE_INSCRICAO_MAX por dia, venham de quantas
     * contas vierem (alternar indeferir/reabrir mandaria um e-mail por vez).
     */
    private const RATE_MAX            = 60;
    private const RATE_WINDOW         = 600;
    private const RATE_INSCRICAO_MAX  = 6;

    /** Chave do contador de ações de status deste usuário (WordPress ou portal). */
    public static function rate_key(): string
    {
        return 'acoes_status:' . md5(Ctx::user_key());
    }

    private static function rate_ok(): bool
    {
        return SecurityManager::hit(self::rate_key(), self::RATE_WINDOW) <= self::RATE_MAX;
    }

    /** Chave do contador de indeferimentos/reaberturas de uma inscrição. */
    public static function rate_key_inscricao(int $post_id): string
    {
        return 'acoes_inscricao:' . $post_id;
    }

    private static function rate_inscricao_ok(int $post_id): bool
    {
        return SecurityManager::hit(self::rate_key_inscricao($post_id), DAY_IN_SECONDS) <= self::RATE_INSCRICAO_MAX;
    }

    /**
     * Texto de um campo do formulário, sanitizado como texto de várias linhas.
     * Quebras de linha viram "\n": o tamanho conferido no servidor é o mesmo
     * que o navegador conta no maxlength (que envia "\r\n").
     */
    private static function post_textarea(string $key): string
    {
        $value = isset($_POST[$key]) && is_string($_POST[$key]) ? wp_unslash($_POST[$key]) : '';
        $value = str_replace(["\r\n", "\r"], "\n", $value);

        return trim(sanitize_textarea_field($value));
    }

    /** @param array<string, scalar> $args */
    private static function back(int $id, array $args): never
    {
        Ctx::redirect(Ctx::url('inscricoes', ['view' => $id] + $args));
    }

    // -----------------------------------------------------------------------
    // Caixa de ações
    // -----------------------------------------------------------------------

    /** @param array<string, mixed> $row Resultado de CptInscricao::get(). */
    public static function render_box(array $row): void
    {
        $id     = (int) $row['id'];
        $status = (string) $row['status'];
        $post   = Ctx::form_action();

        echo '<div class="festa-box festa-actions-box"><h2>Ações da Comissão</h2>';

        switch ($status) {
            // ------------------------------------------------------------------
            // Fase 2: upload do deferimento (status = aguardando_analise)
            // ------------------------------------------------------------------
            case StateMachine::STATUS_ANALISE:
                if (! Ctx::can('analisar')) {
                    echo '<p class="description">Aguardando a análise e o deferimento pela Comissão.</p>';
                    break;
                }
                ?>
                <p><strong>Fase 2 — Deferimento.</strong>
                Confira os dados e os documentos, assine o deferimento e envie o arquivo abaixo.</p>
                <?php echo self::vagas_line(); ?>
                <?php if (Handler::path($id, '_upload_ficha_assinada') === '') : ?>
                  <p class="festa-warn">O participante ainda não enviou a ficha assinada.</p>
                <?php endif; ?>
                <?php if (Vagas::esgotadas()) : ?>
                  <p class="festa-warn festa-vagas-esgotadas" role="alert">
                    <strong>Não há vagas restantes.</strong> O limite de <?php echo (int) Vagas::limite(); ?> vagas foi atingido:
                    não é possível deferir. Para deferir, aumente o limite em Configurações ou aguarde uma vaga.
                  </p>
                  <p><button type="button" class="button button-primary" disabled>&#10003; Deferir inscrição</button></p>
                <?php else : ?>
                  <form method="post" enctype="multipart/form-data" action="<?php echo esc_url($post); ?>" class="festa-stack">
                    <?php echo Ctx::form_fields('festa_deferir', 'festa_deferir_' . $id); ?>
                    <input type="hidden" name="id" value="<?php echo $id; ?>">
                    <label for="festa-deferimento">Documento de deferimento</label>
                    <input type="file" id="festa-deferimento" name="documento" accept="<?php echo esc_attr(Handler::accept_attr('doc')); ?>" required>
                    <span class="description"><?php echo esc_html(Handler::accept_hint('doc') . ' · até ' . Handler::human_size(Handler::max_bytes())); ?></span>
                    <p><button type="submit" class="button button-primary">&#10003; Deferir inscrição</button></p>
                  </form>
                <?php endif; ?>
                <?php
                self::render_indeferir_form($id);
                break;

            // ------------------------------------------------------------------
            // Lista de espera: posição na fila e indeferimento
            // ------------------------------------------------------------------
            case StateMachine::STATUS_LISTA_ESPERA:
                $pos = Vagas::posicao($id);
                ?>
                <p><strong>Lista de espera.</strong>
                  <?php
                  echo esc_html(
                      ($pos > 0 ? sprintf('%dº da fila', $pos) : 'Na fila')
                      . ($row['fila_em'] !== '' ? ', desde ' . mysql2date('d/m/Y \à\s H:i', (string) $row['fila_em']) : '')
                      . '.'
                  );
                  ?>
                </p>
                <p class="description">A inscrição foi finalizada com as vagas esgotadas. Ela vai para a análise quando
                for chamada pelo botão “Chamar próximo da fila”, na lista de inscrições, na ordem de chegada.</p>
                <?php echo self::vagas_line(); ?>
                <?php if (Ctx::can('analisar')) : ?>
                  <p><a class="button" href="<?php echo esc_url(Ctx::url('inscricoes', ['status' => StateMachine::STATUS_LISTA_ESPERA])); ?>">Ver a lista de espera</a></p>
                  <?php self::render_indeferir_form($id); ?>
                <?php endif; ?>
                <?php
                break;

            // ------------------------------------------------------------------
            // Indeferida: justificativa e reabertura
            // ------------------------------------------------------------------
            case StateMachine::STATUS_INDEFERIDO:
                ?>
                <p class="festa-indeferido-head"><strong>Inscrição indeferida</strong><?php
                    echo $row['indeferido_em'] !== '' ? esc_html(' em ' . mysql2date('d/m/Y \à\s H:i', (string) $row['indeferido_em'])) : '';
                ?>.</p>
                <?php if ($row['indeferimento'] !== '') : ?>
                  <div class="festa-justificativa">
                    <span class="festa-sub-text">Justificativa</span>
                    <p><?php echo nl2br(esc_html((string) $row['indeferimento'])); ?></p>
                  </div>
                <?php endif; ?>
                <?php if (Ctx::can('analisar')) : ?>
                  <form method="post" action="<?php echo esc_url($post); ?>" class="festa-stack festa-reabrir"
                        data-festa-confirm="Reabrir esta inscrição para análise? O participante será avisado por e-mail.">
                    <?php echo Ctx::form_fields('festa_reabrir', 'festa_reabrir_' . $id); ?>
                    <input type="hidden" name="id" value="<?php echo $id; ?>">
                    <label for="festa-motivo-reabertura">Motivo da reabertura</label>
                    <textarea id="festa-motivo-reabertura" name="motivo" rows="3" class="widefat"
                              minlength="<?php echo (int) StateMachine::MOTIVO_MIN; ?>" maxlength="<?php echo (int) StateMachine::MOTIVO_MAX; ?>" required
                              placeholder="Ex.: documentação regularizada pelo participante."></textarea>
                    <span class="description">Obrigatório (mínimo de <?php echo (int) StateMachine::MOTIVO_MIN; ?> caracteres). Use para corrigir um engano ou quando a documentação foi regularizada. A inscrição volta para “Aguardando Análise”.</span>
                    <p><button type="submit" class="button">&#8634; Reabrir para análise</button></p>
                  </form>
                <?php else : ?>
                  <p class="description">Só a Comissão pode reabrir uma inscrição indeferida.</p>
                <?php endif; ?>
                <?php
                break;

            // ------------------------------------------------------------------
            // Fase 3: sorteio (status = deferido)
            // ------------------------------------------------------------------
            case StateMachine::STATUS_DEFERIDO:
                if (! Ctx::can('tenda')) {
                    echo '<p class="description">Aguardando o sorteio da tenda.</p>';
                    break;
                }
                ?>
                <p><strong>Fase 3 — Sorteio.</strong>
                Informe o número da tenda sorteada. O participante recebe o número por e-mail e passa a enviar os comprovantes.</p>
                <form method="post" action="<?php echo esc_url($post); ?>" class="festa-stack">
                  <?php echo Ctx::form_fields('festa_tenda', 'festa_tenda_' . $id); ?>
                  <input type="hidden" name="id" value="<?php echo $id; ?>">
                  <label for="festa-numero-tenda">Número da tenda</label>
                  <input type="text" id="festa-numero-tenda" name="numero_tenda" class="widefat" placeholder="Ex: A-12" maxlength="40" required>
                  <p><button type="submit" class="button button-primary">Registrar sorteio</button></p>
                </form>
                <?php
                break;

            // ------------------------------------------------------------------
            // Fase 4: validação dupla (status = aguardando_comprovantes)
            // ------------------------------------------------------------------
            case StateMachine::STATUS_COMPROVANTES:
                self::render_comprovantes($row);
                break;

            case StateMachine::STATUS_CONCLUIDO:
                echo '<p class="festa-ok">&#10003; Inscrição concluída. O Termo de Cessão está nos documentos.</p>';
                break;

            default:
                echo '<p class="description">Nenhuma ação disponível: o participante ainda não finalizou a inscrição.</p>';
        }

        echo '</div>';
    }

    /** @param array<string, mixed> $row */
    private static function render_comprovantes(array $row): void
    {
        $id   = (int) $row['id'];
        $post = Ctx::form_action();
        ?>
        <p><strong>Fase 4A — Recibo da tenda</strong></p>
        <?php if ($row['tenda_aprovada']) : ?>
          <p class="festa-ok">&#10003; Recibo da tenda aprovado.</p>
        <?php elseif (! $row['recibo_enviado']) : ?>
          <p class="description">Aguardando o participante enviar o recibo.</p>
        <?php elseif (Ctx::can('recibo')) : ?>
          <p>Recibo enviado pelo participante.
            <a href="<?php echo esc_url(Panel::file_url($id, 'recibo_tenda', false)); ?>" target="_blank" rel="noopener">Abrir o recibo</a>, confira e aprove.</p>
          <form method="post" action="<?php echo esc_url($post); ?>">
            <?php echo Ctx::form_fields('festa_recibo', 'festa_recibo_' . $id); ?>
            <input type="hidden" name="id" value="<?php echo $id; ?>">
            <button type="submit" class="button button-primary">&#10003; Aprovar recibo da tenda</button>
          </form>
        <?php else : ?>
          <p class="description">Recibo enviado. Aguardando a aprovação da Comissão.</p>
        <?php endif; ?>

        <hr>
        <p><strong>Fase 4B — Doação de colchões</strong></p>
        <?php if ($row['colchoes']) : ?>
          <p class="festa-ok">&#10003; Entrega confirmada pela Defesa Civil.</p>
        <?php elseif (Ctx::can('colchoes')) : ?>
          <p class="description">Confirme somente depois que os colchões forem entregues fisicamente.</p>
          <form method="post" action="<?php echo esc_url($post); ?>"
                data-festa-confirm="Confirmar que os colchões desta inscrição foram entregues?">
            <?php echo Ctx::form_fields('festa_colchoes', 'festa_colchoes_' . $id); ?>
            <input type="hidden" name="id" value="<?php echo $id; ?>">
            <input type="hidden" name="origem" value="inscricao">
            <button type="submit" class="button">&#10003; Confirmar entrega de colchões</button>
          </form>
        <?php else : ?>
          <p class="description">Aguardando a confirmação da Defesa Civil.</p>
        <?php endif; ?>

        <?php if (Ctx::can('tenda')) : ?>
          <hr>
          <form method="post" action="<?php echo esc_url($post); ?>" class="festa-stack">
            <?php echo Ctx::form_fields('festa_tenda', 'festa_tenda_' . $id); ?>
            <input type="hidden" name="id" value="<?php echo $id; ?>">
            <label for="festa-numero-tenda">Corrigir o número da tenda</label>
            <input type="text" id="festa-numero-tenda" name="numero_tenda" class="widefat" value="<?php echo esc_attr((string) $row['tenda']); ?>" maxlength="40" required>
            <p><button type="submit" class="button button-small">Salvar número</button></p>
          </form>
        <?php endif; ?>
        <?php
    }

    /** "Vagas restantes: X de N." (vazio quando não há limite). */
    private static function vagas_line(): string
    {
        $restantes = Vagas::restantes();
        if ($restantes === null) {
            return '';
        }

        return '<p class="festa-vagas-line">' . esc_html(sprintf('Vagas restantes: %d de %d.', $restantes, Vagas::limite())) . '</p>';
    }

    /** Formulário de indeferimento, com a justificativa obrigatória. */
    private static function render_indeferir_form(int $id): void
    {
        ?>
        <details class="festa-indeferir">
          <summary>Indeferir inscrição…</summary>
          <form method="post" action="<?php echo esc_url(Ctx::form_action()); ?>" class="festa-stack"
                data-festa-confirm="Indeferir esta inscrição? O participante será avisado por e-mail, com a justificativa, e não poderá mais enviar documentos.">
            <?php echo Ctx::form_fields('festa_indeferir', 'festa_indeferir_' . $id); ?>
            <input type="hidden" name="id" value="<?php echo (int) $id; ?>">
            <label for="festa-justificativa">Justificativa do indeferimento</label>
            <textarea id="festa-justificativa" name="justificativa" rows="4" class="widefat"
                      minlength="<?php echo (int) StateMachine::MOTIVO_MIN; ?>" maxlength="<?php echo (int) StateMachine::MOTIVO_MAX; ?>" required
                      placeholder="Ex.: documento de identidade ilegível e ficha sem assinatura."></textarea>
            <span class="description">Obrigatória (mínimo de <?php echo (int) StateMachine::MOTIVO_MIN; ?> caracteres). O participante vê este texto no painel e no e-mail.</span>
            <p><button type="submit" class="button festa-button-danger">&#10007; Indeferir inscrição</button></p>
          </form>
        </details>
        <?php
    }

    // -----------------------------------------------------------------------
    // Ação: upload do deferimento
    // -----------------------------------------------------------------------

    /**
     * Deferimento. A conferência de vaga, o upload e a transição acontecem
     * com a trava de Vagas: de dois deferimentos simultâneos disputando a
     * última vaga, o segundo espera o primeiro e encontra as vagas esgotadas.
     */
    public static function deferir(): void
    {
        Ctx::require_post();
        $post_id = Panel::post_id();
        Ctx::guard('analisar', 'festa_deferir_' . $post_id);

        if (! CptInscricao::exists($post_id)) {
            Ctx::stop('Inscrição não encontrada.', 404);
        }

        if (! Vagas::travar()) {
            self::back($post_id, ['msg' => 'vagas_ocupado']);
        }
        $result = self::deferir_travado($post_id);
        Vagas::destravar();

        if (isset($result['erro'])) {
            Panel::back_with_error($post_id, $result['erro']);
        }
        self::back($post_id, ['msg' => $result['msg']]);
    }

    /** @return array{msg?: string, erro?: string} */
    private static function deferir_travado(int $post_id): array
    {
        // Status e vagas relidos depois da trava: outra requisição pode ter
        // mudado ambos enquanto esta esperava.
        wp_cache_delete($post_id, 'post_meta');

        // Confirma status correto antes de agir (evita race condition)
        if (StateMachine::current($post_id) !== StateMachine::STATUS_ANALISE) {
            return ['msg' => 'status_erro'];
        }
        if (Vagas::esgotadas()) {
            return ['msg' => 'vagas_esgotadas'];
        }

        $file = Handler::uploaded('documento');
        if ($file === null) {
            return ['msg' => 'sem_arquivo'];
        }

        // Upload do documento de deferimento
        try {
            Handler::handle_upload($file, $post_id, '_upload_deferimento');
        } catch (\RuntimeException $e) {
            return ['erro' => $e->getMessage()];
        }
        AuditLog::record($post_id, 'documento', 'Documento enviado: Deferimento da Comissão.');

        // Transição de estado
        try {
            StateMachine::transition($post_id, StateMachine::STATUS_DEFERIDO, get_current_user_id());
        } catch (\LogicException | \RuntimeException $e) {
            return ['erro' => 'Arquivo salvo, mas erro na transição de status: ' . $e->getMessage()];
        }

        return ['msg' => 'deferido'];
    }

    // -----------------------------------------------------------------------
    // Ação: indeferir (aguardando_analise ou lista_espera → indeferido)
    // -----------------------------------------------------------------------

    public static function indeferir(): void
    {
        Ctx::require_post();
        $post_id = Panel::post_id();
        Ctx::guard('analisar', 'festa_indeferir_' . $post_id);

        if (! CptInscricao::exists($post_id)) {
            Ctx::stop('Inscrição não encontrada.', 404);
        }

        $justificativa = self::post_textarea('justificativa');
        if (! StateMachine::motivo_valido($justificativa)) {
            self::back($post_id, ['msg' => 'justificativa_curta']);
        }
        if (! self::rate_ok() || ! self::rate_inscricao_ok($post_id)) {
            self::back($post_id, ['msg' => 'rate_limit']);
        }

        $result = self::mudar_status_travado(
            $post_id,
            [StateMachine::STATUS_ANALISE, StateMachine::STATUS_LISTA_ESPERA],
            StateMachine::STATUS_INDEFERIDO,
            $justificativa
        );
        self::back($post_id, ['msg' => $result === '' ? 'indeferido' : $result]);
    }

    // -----------------------------------------------------------------------
    // Ação: reabrir (indeferido → aguardando_analise)
    // -----------------------------------------------------------------------

    public static function reabrir(): void
    {
        Ctx::require_post();
        $post_id = Panel::post_id();
        Ctx::guard('analisar', 'festa_reabrir_' . $post_id);

        if (! CptInscricao::exists($post_id)) {
            Ctx::stop('Inscrição não encontrada.', 404);
        }

        $motivo = self::post_textarea('motivo');
        if (! StateMachine::motivo_valido($motivo)) {
            self::back($post_id, ['msg' => 'motivo_curto']);
        }
        if (! self::rate_ok() || ! self::rate_inscricao_ok($post_id)) {
            self::back($post_id, ['msg' => 'rate_limit']);
        }

        $result = self::mudar_status_travado(
            $post_id,
            [StateMachine::STATUS_INDEFERIDO],
            StateMachine::STATUS_ANALISE,
            $motivo
        );
        self::back($post_id, ['msg' => $result === '' ? 'reaberto' : $result]);
    }

    /**
     * Transição de uma inscrição que está num dos status esperados, com a
     * trava de Vagas (a chamada da fila disputa as mesmas inscrições).
     *
     * @param list<string> $de
     * @return string '' se deu certo; senão o código da mensagem de erro.
     */
    private static function mudar_status_travado(int $post_id, array $de, string $para, string $motivo): string
    {
        if (! Vagas::travar()) {
            return 'vagas_ocupado';
        }

        wp_cache_delete($post_id, 'post_meta');
        $erro = '';
        if (! in_array(StateMachine::current($post_id), $de, true)) {
            $erro = 'status_erro';
        } else {
            try {
                StateMachine::transition($post_id, $para, get_current_user_id(), $motivo);
            } catch (\LogicException | \RuntimeException $e) {
                Vagas::destravar();
                Panel::back_with_error($post_id, 'Não foi possível mudar o status: ' . $e->getMessage());
            }
        }

        Vagas::destravar();

        return $erro;
    }

    // -----------------------------------------------------------------------
    // Ação: chamar o próximo da lista de espera
    // -----------------------------------------------------------------------

    /**
     * Move o primeiro da fila (menor `_fila_em`) de lista_espera para
     * aguardando_analise. Só com vaga restante; o participante é avisado.
     */
    public static function chamar_fila(): void
    {
        Ctx::require_post();
        Ctx::guard('analisar', 'festa_chamar_fila');

        $lista = ['status' => StateMachine::STATUS_LISTA_ESPERA];

        if (! self::rate_ok()) {
            Ctx::redirect(Ctx::url('inscricoes', $lista + ['msg' => 'rate_limit']));
        }
        if (! Vagas::travar()) {
            Ctx::redirect(Ctx::url('inscricoes', $lista + ['msg' => 'vagas_ocupado']));
        }

        $id  = Vagas::proximo();
        $msg = 'chamado';
        if ($id === 0) {
            $msg = 'fila_vazia';
        } elseif (Vagas::esgotadas()) {
            $msg = 'fila_sem_vaga';
        } else {
            wp_cache_delete($id, 'post_meta');
            try {
                StateMachine::transition($id, StateMachine::STATUS_ANALISE, get_current_user_id());
            } catch (\LogicException | \RuntimeException $e) {
                Vagas::destravar();
                Ctx::flash_set('erro', 'Não foi possível chamar da fila: ' . $e->getMessage());
                Ctx::redirect(Ctx::url('inscricoes', $lista + ['msg' => 'erro']));
            }
        }
        Vagas::destravar();

        Ctx::redirect(Ctx::url('inscricoes', $lista + ['msg' => $msg] + ($msg === 'chamado' ? ['chamado' => $id] : [])));
    }

    // -----------------------------------------------------------------------
    // Ação: número da tenda (sorteio)
    // -----------------------------------------------------------------------

    /**
     * Grava `_numero_tenda`. Na primeira vez (status = deferido), o registro
     * do sorteio leva a inscrição para aguardando_comprovantes e o
     * participante é avisado por e-mail; depois disso, só corrige o número.
     */
    public static function tenda(): void
    {
        Ctx::require_post();
        $post_id = Panel::post_id();
        Ctx::guard('tenda', 'festa_tenda_' . $post_id);

        if (! CptInscricao::exists($post_id)) {
            Ctx::stop('Inscrição não encontrada.', 404);
        }

        $status = StateMachine::current($post_id);
        if (! in_array($status, [StateMachine::STATUS_DEFERIDO, StateMachine::STATUS_COMPROVANTES], true)) {
            self::back($post_id, ['msg' => 'status_erro']);
        }

        $novo  = sanitize_text_field(isset($_POST['numero_tenda']) && is_string($_POST['numero_tenda']) ? wp_unslash($_POST['numero_tenda']) : '');
        $atual = (string) get_post_meta($post_id, '_numero_tenda', true);

        if ($novo === '') {
            self::back($post_id, ['msg' => 'tenda_erro']);
        }

        if ($novo !== $atual) {
            update_post_meta($post_id, '_numero_tenda', $novo);
            AuditLog::record(
                $post_id,
                'tenda',
                $atual === ''
                    ? sprintf('Número da tenda registrado: %s.', $novo)
                    : sprintf('Número da tenda corrigido: %s → %s.', $atual, $novo)
            );
        }

        if ($status !== StateMachine::STATUS_DEFERIDO) {
            self::back($post_id, ['msg' => $novo !== $atual ? 'tenda_fix' : 'nochange']);
        }

        try {
            StateMachine::transition($post_id, StateMachine::STATUS_COMPROVANTES, get_current_user_id());
        } catch (\LogicException | \RuntimeException $e) {
            Panel::back_with_error($post_id, 'Número salvo, mas erro na transição de status: ' . $e->getMessage());
        }

        self::back($post_id, ['msg' => 'tenda']);
    }

    // -----------------------------------------------------------------------
    // Ação: aprovar recibo da tenda
    // -----------------------------------------------------------------------

    public static function aprovar_recibo(): void
    {
        Ctx::require_post();
        $post_id = Panel::post_id();
        Ctx::guard('recibo', 'festa_recibo_' . $post_id);

        if (! CptInscricao::exists($post_id)) {
            Ctx::stop('Inscrição não encontrada.', 404);
        }

        if (
            StateMachine::current($post_id) !== StateMachine::STATUS_COMPROVANTES
            || (string) get_post_meta($post_id, '_upload_recibo_tenda', true) === ''
        ) {
            self::back($post_id, ['msg' => 'status_erro']);
        }

        // Idempotência: não re-aprovar
        if ((bool) get_post_meta($post_id, '_tenda_aprovada', true)) {
            self::back($post_id, ['msg' => 'recibo']);
        }

        // Seta flag
        update_post_meta($post_id, '_tenda_aprovada', true);
        AuditLog::record($post_id, 'recibo_aprovado', 'Recibo da tenda aprovado.');

        // Avalia condição dupla de conclusão
        if ((bool) get_post_meta($post_id, '_colchoes_confirmados', true)) {
            try {
                StateMachine::transition(
                    $post_id,
                    StateMachine::STATUS_CONCLUIDO,
                    get_current_user_id()
                );
            } catch (\LogicException | \RuntimeException $e) {
                // Flag foi setada — volta com o detalhe da falha de conclusão
                Panel::back_with_error($post_id, 'Recibo aprovado. Erro na conclusão automática: ' . $e->getMessage());
            }
            self::back($post_id, ['msg' => 'concluido']);
        }

        self::back($post_id, ['msg' => 'recibo']);
    }
}
