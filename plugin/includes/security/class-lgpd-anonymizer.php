<?php

declare(strict_types=1);

namespace Festa\Security;

use Festa\Audit\AuditLog;
use Festa\Cpt\CptInscricao;
use Festa\Fields\Fields;
use Festa\Status\StateMachine;

/**
 * Ferramenta de Anonimização em Lote — LGPD Art. 16 / Art. 18.
 *
 * Disponível exclusivamente para administradores/DPO (capability `manage_options`).
 * Processa inscrições concluídas ou indeferidas criadas há mais de 365 dias,
 * sobrescrevendo dados pessoais identificáveis e deletando arquivos físicos
 * de uploads.
 *
 * Princípio de operação:
 *  - NUNCA deletar o post CPT (preservação de histórico administrativo)
 *  - Sobrescrever apenas os campos que identificam a pessoa física
 *  - Deletar fisicamente o subdiretório de uploads da inscrição
 *  - Marcar com `_lgpd_purged = true` para idempotência
 *  - Falha em uma inscrição NÃO interrompe o lote
 */
final class LgpdAnonymizer
{
    /** Slug da sub-página no wp-admin (registrada em Admin\AdminMenu). */
    public const PAGE_SLUG = 'festa_lgpd_anonymizer';

    /** Action name do nonce para o formulário de purge. */
    private const NONCE_ACTION = 'festa_lgpd_purge_batch';

    /** Nome do campo nonce no formulário. */
    private const NONCE_FIELD = 'festa_lgpd_purge_nonce';

    /** Metas PII a serem sobrescritos com a string de anonimização. */
    private const PII_META_KEYS = [
        '_cpf',
        '_cpf_hash',
        '_nome_responsavel',
        '_telefone',
        '_email',
    ];

    /** String substituta para dados anonimizados (distinguível de dado vazio). */
    private const PURGE_PLACEHOLDER = '[ANONIMIZADO_LGPD]';

    /** Mínimo de dias desde o pré-cadastro para elegibilidade de purge. */
    private const MIN_AGE_DAYS = 365;

    /**
     * Status finais elegíveis: concluída (o evento passou) e indeferida (a
     * inscrição não segue). Rascunho, análise, lista de espera e as etapas
     * em andamento nunca são anonimizadas por esta ferramenta.
     *
     * @return list<string>
     */
    private static function eligible_status(): array
    {
        return [StateMachine::STATUS_CONCLUIDO, StateMachine::STATUS_INDEFERIDO];
    }

    /**
     * Textos livres digitados pela Comissão (podem citar pessoas): apagados
     * junto com os dados pessoais.
     */
    private const FREE_TEXT_META_KEYS = [
        '_observacoes',
        '_indeferimento_motivo',
        '_reabertura_motivo',
    ];

    // -----------------------------------------------------------------------
    // Página administrativa
    // -----------------------------------------------------------------------

    public static function render_page(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die('Acesso não autorizado.', '', ['response' => 403]);
        }

        $result = null;

        // Processa submissão do formulário de purge
        if (
            isset($_POST[self::NONCE_FIELD])
            && check_admin_referer(self::NONCE_ACTION, self::NONCE_FIELD)
        ) {
            $result = self::run_batch_purge();
        }

        // Contagem de elegíveis para exibição na UI
        $eligible_count = self::count_eligible();

        ?>
        <div class="wrap">
          <h1>Ferramentas LGPD — Anonimização de Dados</h1>
          <p style="color:#6e6e73;max-width:680px;line-height:1.6">
            Esta ferramenta localiza inscrições com status <strong>Concluído</strong> ou
            <strong>Indeferido</strong> criadas há mais de <?php echo self::MIN_AGE_DAYS; ?> dias e substitui os dados
            pessoais identificáveis (CPF, nome, telefone, e-mail) e os textos livres da equipe
            (observações, justificativa do indeferimento, motivo da reabertura) pela string
            <code><?php echo esc_html(self::PURGE_PLACEHOLDER); ?></code>,
            deletando também os arquivos físicos de upload associados.
            O post CPT e o histórico administrativo são preservados integralmente.
          </p>

          <div style="margin:24px 0;padding:20px 24px;background:#fff;border:1px solid #ddd;
                      border-radius:8px;max-width:680px">
            <table class="form-table" role="presentation" style="margin:0">
              <tr>
                <th scope="row" style="padding:8px 0">Elegíveis para anonimização</th>
                <td style="padding:8px 0">
                  <strong style="font-size:18px"><?php echo (int) $eligible_count; ?></strong>
                  <span style="color:#6e6e73;margin-left:6px;font-size:13px">inscrição(ões)</span>
                </td>
              </tr>
              <tr>
                <th scope="row" style="padding:8px 0">Critério de elegibilidade</th>
                <td style="padding:8px 0;font-size:13px;color:#3a3a3c">
                  Status = Concluído ou Indeferido · Criado há &gt; <?php echo self::MIN_AGE_DAYS; ?> dias ·
                  Sem flag <code>_lgpd_purged</code>
                </td>
              </tr>
            </table>
          </div>

          <?php if ($result !== null) : ?>
            <div class="notice notice-<?php echo $result['errors'] === 0 ? 'success' : 'warning'; ?> inline"
                 style="max-width:680px;margin-bottom:16px">
              <p>
                <strong>Lote concluído.</strong>
                <?php
                printf(
                    '%d inscrição(ões) anonimizadas com sucesso. %d erro(s) (ver error_log).',
                    $result['purged'],
                    $result['errors']
                );
                ?>
              </p>
            </div>
          <?php endif; ?>

          <?php if ($eligible_count > 0) : ?>
            <form method="post" action="">
              <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD); ?>
              <p style="max-width:680px;padding:16px;background:#fff3d6;border-left:4px solid #b45309;
                         border-radius:0 8px 8px 0;font-size:13px;color:#3a3a3c;margin-bottom:16px">
                <strong>&#9888; Atenção:</strong> Esta ação é <strong>irreversível</strong>.
                Os dados pessoais serão permanentemente substituídos e os arquivos deletados.
                Execute apenas após confirmação com o DPO responsável.
              </p>
              <?php
              submit_button(
                  'Executar Anonimização em Lote (' . (int) $eligible_count . ' registro(s))',
                  'primary large',
                  'submit',
                  false
              );
              ?>
            </form>
          <?php else : ?>
            <div class="notice notice-info inline" style="max-width:680px">
              <p>Nenhuma inscrição elegível para anonimização no momento.</p>
            </div>
          <?php endif; ?>
        </div>
        <?php
    }

    // -----------------------------------------------------------------------
    // Lógica de anonimização em lote
    // -----------------------------------------------------------------------

    /**
     * Executa o purge em todas as inscrições elegíveis.
     *
     * @return array{purged: int, errors: int}
     */
    private static function run_batch_purge(): array
    {
        $ids    = self::get_eligible_ids();
        $purged = 0;
        $errors = 0;

        // Inicializa WP_Filesystem uma única vez antes do loop
        self::init_wp_filesystem();

        foreach ($ids as $post_id) {
            try {
                self::purge_single((int) $post_id);
                $purged++;
            } catch (\Throwable $e) {
                // Falha em uma inscrição não interrompe o lote — Observer resiliente
                $errors++;
                error_log(sprintf(
                    '[Festa\Security\LgpdAnonymizer] Falha ao anonimizar inscrição #%d: %s',
                    $post_id,
                    $e->getMessage()
                ));
            }
        }

        return ['purged' => $purged, 'errors' => $errors];
    }

    /**
     * Anonimiza uma única inscrição.
     * Atômico no sentido de que ou tudo é processado ou uma exceção é lançada.
     *
     * @throws \RuntimeException Em caso de falha crítica durante o processamento.
     */
    private static function purge_single(int $post_id): void
    {
        // 1. Sobrescrever todos os campos PII com o placeholder
        foreach (self::PII_META_KEYS as $meta_key) {
            update_post_meta($post_id, $meta_key, self::PURGE_PLACEHOLDER);
        }

        // 2. Deletar também os metadados de referência a arquivos (orphans após deleção),
        //    os textos livres da equipe (podem citar pessoas) e a senha de acesso
        foreach (Fields::upload_meta_keys() as $meta_key) {
            delete_post_meta($post_id, $meta_key);
            delete_post_meta($post_id, $meta_key . '_info');
        }
        foreach (self::FREE_TEXT_META_KEYS as $meta_key) {
            delete_post_meta($post_id, $meta_key);
        }
        delete_post_meta($post_id, '_senha_hash');

        // 2b. Na auditoria, as transições guardam a justificativa/motivo digitado:
        //     o registro (quem, quando, de qual status para qual) fica; o texto sai.
        AuditLog::redact_free_text($post_id, self::PURGE_PLACEHOLDER);

        // 3. Deletar fisicamente o subdiretório de uploads da inscrição
        self::delete_upload_directory($post_id);

        // 4. Setar flag de idempotência — impede re-processamento futuro
        update_post_meta($post_id, '_lgpd_purged', true);

        AuditLog::record($post_id, 'lgpd', 'Dados pessoais anonimizados e documentos removidos.');
    }

    /**
     * Deleta recursivamente o subdiretório de uploads de uma inscrição.
     * Proteção anti directory-traversal via realpath() boundary check.
     *
     * @throws \RuntimeException Se o WP_Filesystem não estiver disponível.
     */
    private static function delete_upload_directory(int $post_id): void
    {
        global $wp_filesystem;

        if (! $wp_filesystem instanceof \WP_Filesystem_Base) {
            throw new \RuntimeException('WP_Filesystem não inicializado.');
        }

        $base_dir = FESTA_UPLOADS_DIR;
        // Subdiretório específico desta inscrição: uploads/{post_id}/
        $target   = rtrim($base_dir, '/\\') . DIRECTORY_SEPARATOR . $post_id;

        // Valida existência antes de checar realpath
        if (! is_dir($target)) {
            return; // Já inexistente — sem erro
        }

        $real_base   = realpath($base_dir);
        $real_target = realpath($target);

        // Guard anti-traversal: o alvo deve estar estritamente dentro do diretório base
        if (
            $real_base === false
            || $real_target === false
            || ! str_starts_with($real_target, $real_base . DIRECTORY_SEPARATOR)
        ) {
            throw new \RuntimeException(
                sprintf('Caminho fora do diretório autorizado: %s', $target)
            );
        }

        // Deleção recursiva via WP_Filesystem (portable e auditável)
        if (! $wp_filesystem->rmdir($real_target, true)) {
            throw new \RuntimeException(
                sprintf('Falha ao deletar diretório de uploads: %s', $real_target)
            );
        }
    }

    // -----------------------------------------------------------------------
    // Queries auxiliares
    // -----------------------------------------------------------------------

    /**
     * Retorna IDs de posts elegíveis para anonimização.
     *
     * @return int[]
     */
    private static function get_eligible_ids(): array
    {
        $cutoff = gmdate('Y-m-d H:i:s', strtotime('-' . self::MIN_AGE_DAYS . ' days'));

        $query = new \WP_Query([
            'post_type'      => CptInscricao::POST_TYPE,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'date_query'     => [
                ['before' => $cutoff, 'inclusive' => true],
            ],
            'meta_query'     => [
                'relation' => 'AND',
                [
                    'key'     => '_status',
                    'value'   => self::eligible_status(),
                    'compare' => 'IN',
                ],
                [
                    'key'     => '_lgpd_purged',
                    'compare' => 'NOT EXISTS',
                ],
            ],
        ]);

        return array_map('intval', (array) $query->posts);
    }

    /** Retorna a contagem de elegíveis sem carregar os posts completos. */
    private static function count_eligible(): int
    {
        return count(self::get_eligible_ids());
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Inicializa a WP_Filesystem API para operações seguras de arquivo.
     * Garante que o contexto do sistema de arquivos direto esteja disponível.
     */
    private static function init_wp_filesystem(): void
    {
        global $wp_filesystem;

        if ($wp_filesystem instanceof \WP_Filesystem_Base) {
            return;
        }

        if (! function_exists('WP_Filesystem')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        WP_Filesystem();
    }
}
