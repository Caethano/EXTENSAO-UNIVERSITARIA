<?php

declare(strict_types=1);

namespace Festa\Admin;

use Festa\Audit\AuditLog;
use Festa\Cpt\CptInscricao;
use Festa\Crypto\Crypto;
use Festa\Fields\Fields;
use Festa\Pdf\Generator;
use Festa\Status\StateMachine;
use Festa\Upload\Handler;

/**
 * Página e ação da Defesa Civil.
 *
 * Acesso: exclusivamente quem tem a permissão de confirmar colchões
 * (capability `festa_confirmar_colchoes` no wp-admin; "colchoes" no portal).
 * A Defesa Civil NUNCA vê a listagem geral de inscrições.
 * A única forma de acessar um registro é via busca por CPF (hash).
 *
 * LGPD: a busca é enviada por POST — o CPF digitado não fica no endereço da
 * página, no histórico do navegador nem nos logs de acesso do servidor.
 */
final class AdminDefesa
{
    private const NONCE_BUSCA = 'festa_colchoes_busca';

    public static function boot_hooks(): void
    {
        add_action('admin_post_festa_colchoes', [self::class, 'confirmar']);
    }

    /**
     * A busca não altera nada: a ação existe só para o envio ser conferido
     * (nonce no wp-admin, token da sessão no portal) antes de a tela exibir
     * o resultado.
     */
    public static function buscar(): void
    {
        Ctx::guard('colchoes', self::NONCE_BUSCA);
    }

    // -----------------------------------------------------------------------
    // Página de busca
    // -----------------------------------------------------------------------

    public static function render_page(): void
    {
        if (! Ctx::can('colchoes')) {
            Panel::no_access();
            return;
        }

        $search_cpf = '';
        if (isset($_POST['cpf']) && is_string($_POST['cpf'])) {
            self::buscar();
            $search_cpf = sanitize_text_field(wp_unslash($_POST['cpf']));
        }

        // Resultado da confirmação que acabou de ser feita.
        $flash = Ctx::flash_get('colchoes');
        ?>
        <div class="wrap festa-admin">
          <h1>Busca de Colchões — Festa dos Amigos 2026</h1>
          <p class="festa-page-hint">
            Busque pelo CPF do responsável para localizar a inscrição e confirmar a entrega de colchões.
          </p>

          <?php if (is_array($flash)) : ?>
            <div class="notice notice-<?php echo esc_attr((string) $flash['tipo']); ?>"><p><?php echo esc_html((string) $flash['msg']); ?></p></div>
          <?php endif; ?>

          <!-- Formulário de busca -->
          <div class="festa-box festa-busca">
            <form method="post" action="<?php echo esc_url(Ctx::url('colchoes')); ?>" autocomplete="off">
              <?php echo Ctx::form_fields('festa_colchoes_busca', self::NONCE_BUSCA); ?>
              <label for="festa-cpf-search"><strong>CPF do responsável</strong></label>
              <?php // O CPF digitado não é devolvido para a tela: o campo volta vazio após a busca. ?>
              <input type="text" id="festa-cpf-search" name="cpf"
                     placeholder="000.000.000-00" class="regular-text" inputmode="numeric" maxlength="14"
                     autocomplete="off" required>
              <button type="submit" class="button button-primary">Buscar</button>
            </form>
          </div>

          <?php
          if (is_array($flash) && ! empty($flash['id'])) {
              // Depois de confirmar, mostra a inscrição sem precisar buscar de novo.
              self::render_result((int) $flash['id']);
          } elseif ($search_cpf !== '') {
              self::render_search_results($search_cpf);
          }
          ?>
        </div><!-- .wrap -->
        <?php
    }

    // -----------------------------------------------------------------------
    // Resultado da busca
    // -----------------------------------------------------------------------

    private static function render_search_results(string $search_cpf): void
    {
        $cpf_digits = Fields::digits($search_cpf);

        if (! Fields::valid_cpf($cpf_digits)) {
            echo '<div class="notice notice-error inline"><p>CPF inválido. Confira os 11 dígitos informados.</p></div>';
            return;
        }

        // Busca via HMAC hash — CPF nunca vai para o banco em plaintext
        $query = new \WP_Query([
            'post_type'      => CptInscricao::POST_TYPE,
            'post_status'    => 'publish',
            'meta_query'     => [['key' => '_cpf_hash', 'value' => Crypto::hash($cpf_digits), 'compare' => '=']],
            'fields'         => 'ids',
            'posts_per_page' => 1,
            'no_found_rows'  => true,
        ]);

        if (! $query->have_posts()) {
            echo '<div class="notice notice-warning inline"><p>'
                . 'Nenhuma inscrição encontrada para o CPF informado.</p></div>';
            return;
        }

        self::render_result((int) $query->posts[0]);
    }

    // -----------------------------------------------------------------------
    // Inscrições encontradas pela busca
    // -----------------------------------------------------------------------

    /** Por quanto tempo o comprovante de uma inscrição encontrada pode ser aberto. */
    private const FOUND_TTL = HOUR_IN_SECONDS;

    private static function found_key(): string
    {
        return 'festa_defesa_ids_' . md5(Ctx::user_key());
    }

    /** Guarda (no servidor) que este usuário encontrou a inscrição pela busca por CPF. */
    private static function remember_found(int $post_id): void
    {
        $now   = time();
        $found = get_transient(self::found_key());
        $found = is_array($found) ? array_filter($found, static fn ($exp): bool => (int) $exp > $now) : [];

        $found[$post_id] = $now + self::FOUND_TTL;
        set_transient(self::found_key(), $found, self::FOUND_TTL);
    }

    /**
     * Este usuário encontrou a inscrição pela busca na última hora? Quem só
     * tem a permissão da Defesa Civil abre o comprovante de doação apenas
     * nesse caso — trocar o id no endereço não basta.
     */
    public static function found_recently(int $post_id): bool
    {
        $found = get_transient(self::found_key());

        return is_array($found) && (int) ($found[$post_id] ?? 0) > time();
    }

    private static function render_result(int $post_id): void
    {
        $row = CptInscricao::get($post_id);
        if (! $row) {
            return;
        }
        self::remember_found($post_id);
        ?>
        <h2>Resultado</h2>

        <?php // Tabela de dados (sem CPF em plaintext) ?>
        <table class="widefat striped festa-result">
          <tbody>
            <tr><th>Responsável</th><td><?php echo esc_html($row['nome']); ?></td></tr>
            <tr><th>CPF</th><td><?php echo esc_html($row['cpf']); ?></td></tr>
            <tr><th>Grupo</th><td><?php echo esc_html($row['grupo']); ?></td></tr>
            <tr><th>Protocolo</th><td>#<?php echo (int) $post_id; ?></td></tr>
            <tr><th>Status</th><td><?php echo Panel::status_badge($row['status']); ?></td></tr>
            <tr>
              <th>Colchões confirmados</th>
              <td><?php echo $row['colchoes'] ? '<strong class="festa-ok">&#10003; Sim</strong>' : '<strong class="festa-pending">&#10007; Não</strong>'; ?></td>
            </tr>
          </tbody>
        </table>

        <?php
        // Botão de confirmação — apenas quando elegível
        if ($row['status'] === StateMachine::STATUS_COMPROVANTES && ! $row['colchoes']) {
            self::render_confirm_button($post_id);
        } elseif ($row['colchoes']) {
            echo '<div class="notice notice-success inline"><p>&#10003; Doação de colchões já confirmada para este inscrito.</p></div>';
            if (Handler::path($post_id, '_upload_comprovante_doacao') !== '') {
                printf(
                    '<p><a class="button" href="%s" target="_blank" rel="noopener">Abrir o comprovante de doação (imprimir e assinar)</a></p>',
                    esc_url(Panel::file_url($post_id, 'comprovante_doacao', false))
                );
            }
        } else {
            printf(
                '<div class="notice notice-info inline"><p>Inscrição não está elegível para confirmação de colchões. Status atual: <strong>%s</strong>.</p></div>',
                esc_html(StateMachine::label($row['status']))
            );
        }
    }

    // -----------------------------------------------------------------------
    // Botão "Confirmar Entrega"
    // -----------------------------------------------------------------------

    private static function render_confirm_button(int $post_id): void
    {
        ?>
        <div class="festa-box festa-confirmar">
          <form method="post" action="<?php echo esc_url(Ctx::form_action()); ?>"
                data-festa-confirm="Confirmar que os colchões desta inscrição foram entregues?">
            <?php echo Ctx::form_fields('festa_colchoes', 'festa_colchoes_' . $post_id); ?>
            <input type="hidden" name="id" value="<?php echo (int) $post_id; ?>">
            <p>
              Confirme que os colchões foram entregues fisicamente pelo responsável desta inscrição.
              Esta ação gerará automaticamente o comprovante de doação.
            </p>
            <button type="submit" class="button button-primary button-large">
              &#10003; Confirmar Entrega de Colchões
            </button>
          </form>
        </div>
        <?php
    }

    // -----------------------------------------------------------------------
    // Ação: confirmar colchões
    // -----------------------------------------------------------------------

    public static function confirmar(): void
    {
        Ctx::require_post();
        $post_id = Panel::post_id();

        // Nonce vinculado ao post_id — impede replay em outra inscrição
        Ctx::guard('colchoes', 'festa_colchoes_' . $post_id);

        if (! CptInscricao::exists($post_id)) {
            Ctx::stop('Inscrição não encontrada.', 404);
        }

        // Verifica estado atual
        if (StateMachine::current($post_id) !== StateMachine::STATUS_COMPROVANTES) {
            self::finish($post_id, 'error', 'Inscrição não está aguardando comprovantes.', 'status_erro');
        }

        if ((bool) get_post_meta($post_id, '_colchoes_confirmados', true)) {
            self::finish($post_id, 'info', 'Colchões já confirmados anteriormente.', 'colchoes');
        }

        // 1. Setar flag
        update_post_meta($post_id, '_colchoes_confirmados', true);
        AuditLog::record($post_id, 'colchoes_confirmados', 'Entrega de colchões confirmada.');

        // 2. Gerar comprovante de doação (non-fatal se falhar)
        try {
            $data = [
                'inscricao_id'     => $post_id,
                'nome_responsavel' => (string) get_post_meta($post_id, '_nome_responsavel', true),
                'cpf_masked'       => CptInscricao::masked_cpf($post_id) ?: '***.***.***-**',
                'nome_grupo'       => (string) get_post_meta($post_id, '_nome_grupo', true),
                'data_emissao'     => wp_date('d/m/Y \à\s H:i'),
            ];
            Generator::generate('doacao-colchoes', $data, $post_id, '_upload_comprovante_doacao');
        } catch (\RuntimeException $e) {
            error_log('[Festa\AdminDefesa] Falha ao gerar comprovante de doação: ' . $e->getMessage());
        }

        // 3. Avaliar condição dupla de conclusão
        if ((bool) get_post_meta($post_id, '_tenda_aprovada', true)) {
            try {
                StateMachine::transition(
                    $post_id,
                    StateMachine::STATUS_CONCLUIDO,
                    get_current_user_id()
                );
            } catch (\LogicException | \RuntimeException $e) {
                // Flag já setada e comprovante gerado — sucesso parcial com detalhe
                self::finish($post_id, 'warning', 'Colchões confirmados. Erro na conclusão automática: ' . $e->getMessage(), 'colchoes');
            }
            self::finish($post_id, 'success', 'Colchões confirmados. Inscrição concluída com sucesso!', 'concluido');
        }

        self::finish($post_id, 'success', 'Colchões confirmados com sucesso! Aguardando aprovação do recibo da tenda.', 'colchoes');
    }

    /**
     * Volta para a tela de onde a confirmação partiu: a busca da Defesa
     * Civil (com o resultado) ou a inscrição, no painel da Comissão.
     */
    private static function finish(int $post_id, string $tipo, string $msg, string $panel_msg): never
    {
        $origem = isset($_POST['origem']) && is_string($_POST['origem']) ? sanitize_key($_POST['origem']) : '';

        if ($origem === 'inscricao' && Ctx::can('ver')) {
            if ($tipo === 'warning') {
                Panel::back_with_error($post_id, $msg);
            }
            Ctx::redirect(Ctx::url('inscricoes', ['view' => $post_id, 'msg' => $panel_msg]));
        }

        Ctx::flash_set('colchoes', ['id' => $post_id, 'tipo' => $tipo, 'msg' => $msg]);
        Ctx::redirect(Ctx::url('colchoes'));
    }
}
