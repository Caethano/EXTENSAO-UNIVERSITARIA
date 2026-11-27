<?php

declare(strict_types=1);

namespace Festa\Audit;

use Festa\Admin\Ctx;
use Festa\Admin\Panel;
use Festa\Frontend\AuthManager;
use Festa\Status\StateMachine;

/**
 * Audit Log — Observer Pattern.
 *
 * Registra transições de status e as demais ações auditáveis (documentos,
 * tenda, validações, observações, exportações, exclusões) no banco de dados.
 * Esta classe NUNCA altera dados da inscrição: apenas observa e persiste registros.
 * O histórico exibido na tela de cada inscrição sai daqui.
 *
 * Tabela: {prefix}_festa_audit_log
 * Listener: hook `festa_status_transition` (prioridade 20, após PDF e E-mail)
 *
 * LGPD: IPs são anonimizados ANTES da persistência — último octeto IPv4 zerado,
 * últimos 80 bits IPv6 zerados. O IP exato nunca toca o banco de dados. O
 * detalhe de cada registro descreve a ação, sem dados pessoais; a exceção é
 * o texto que a Comissão digita (justificativa do indeferimento, motivo da
 * reabertura), apagado pela anonimização LGPD (redact_free_text()).
 */
final class AuditLog
{
    /** Nome da tabela sem o prefixo do $wpdb. */
    private const TABLE = 'festa_audit_log';

    /** Slug da sub-página no wp-admin. */
    public const PAGE_SLUG = 'festa_audit_log';

    // -----------------------------------------------------------------------
    // Bootstrap
    // -----------------------------------------------------------------------

    public static function boot_hooks(): void
    {
        // Listener de transições (prioridade 20: após Pdf\Generator p10 e Email p10)
        add_action('festa_status_transition', [self::class, 'log_transition'], 20, 5);
    }

    // -----------------------------------------------------------------------
    // Criação da tabela (chamado em Plugin::activate() e nas atualizações)
    // -----------------------------------------------------------------------

    /**
     * Cria ou atualiza a tabela de audit log via dbDelta.
     * Idempotente: seguro chamar múltiplas vezes (ex.: re-ativação do plugin).
     */
    public static function create_table(): void
    {
        global $wpdb;

        $table   = $wpdb->prefix . self::TABLE;
        $charset = $wpdb->get_charset_collate();

        // dbDelta exige formatação específica: dois espaços após o nome da coluna,
        // PRIMARY KEY e KEY em linhas separadas, sem vírgula após o último item.
        // actor:  nome de quem agiu quando não é um usuário do WordPress
        //         (conta do portal externo ou o próprio participante).
        // detail: descrição da ação, sem dados pessoais.
        $sql = "CREATE TABLE {$table} (
  id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  inscricao_id bigint(20) UNSIGNED NOT NULL DEFAULT 0,
  user_id bigint(20) UNSIGNED NOT NULL DEFAULT 0,
  actor varchar(190) NOT NULL DEFAULT '',
  action varchar(80) NOT NULL DEFAULT '',
  old_value varchar(80) NOT NULL DEFAULT '',
  new_value varchar(80) NOT NULL DEFAULT '',
  detail varchar(1000) NOT NULL DEFAULT '',
  ip_address varchar(45) NOT NULL DEFAULT '',
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY inscricao_id (inscricao_id),
  KEY created_at (created_at)
) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    // -----------------------------------------------------------------------
    // LGPD: anonimização de IP
    // -----------------------------------------------------------------------

    /**
     * Mascara o IP antes do armazenamento, conforme LGPD.
     *
     * IPv4: zera o último octeto       → 192.168.1.100  → 192.168.1.0
     * IPv6: zera os últimos 80 bits    → 2001:db8::1    → 2001:db8::
     * IP inválido/vazio               → 0.0.0.0
     */
    public static function anonymize_ip(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts    = explode('.', $ip);
            $parts[3] = '0';
            return implode('.', $parts);
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $binary = inet_pton($ip);
            if ($binary !== false) {
                // Zera os últimos 10 bytes (80 bits) — mantém os primeiros 6 bytes
                $anonymized = substr($binary, 0, 6) . str_repeat("\x00", 10);
                $result     = inet_ntop($anonymized);
                if ($result !== false) {
                    return $result;
                }
            }
        }

        return '0.0.0.0';
    }

    /** IP da requisição atual, já anonimizado. */
    public static function request_ip(): string
    {
        return self::anonymize_ip(isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '');
    }

    // -----------------------------------------------------------------------
    // Observer: registrar transição de status
    // -----------------------------------------------------------------------

    /**
     * Callback do hook `festa_status_transition`.
     * Captura o IP da requisição, anonimiza e persiste o registro, com a
     * justificativa do indeferimento ou o motivo da reabertura quando houver.
     * Falhas de DB são logadas via error_log — nunca interrompem a execução.
     */
    public static function log_transition(
        int $inscricao_id,
        string $status_atual,
        string $novo_status,
        int $user_id,
        string $motivo = ''
    ): void {
        $detail = match (true) {
            $novo_status === StateMachine::STATUS_INDEFERIDO
                => 'Justificativa: ' . $motivo,
            $status_atual === StateMachine::STATUS_INDEFERIDO
                => 'Motivo da reabertura: ' . $motivo,
            $status_atual === StateMachine::STATUS_LISTA_ESPERA && $novo_status === StateMachine::STATUS_ANALISE
                => 'Chamada da lista de espera.',
            $novo_status === StateMachine::STATUS_LISTA_ESPERA
                => 'Vagas esgotadas: entrou na lista de espera.',
            default => '',
        };

        self::insert($inscricao_id, $user_id, 'status_transition', $detail, $status_atual, $novo_status);
    }

    // -----------------------------------------------------------------------
    // Registro das demais ações
    // -----------------------------------------------------------------------

    /**
     * Registra uma ação auditável feita por quem está na requisição atual
     * (usuário do WordPress, conta do portal ou o participante).
     *
     * @param int    $inscricao_id 0 quando a ação não é sobre uma inscrição.
     * @param string $action       Identificador curto (ver action_label()).
     * @param string $detail       Descrição legível, sem dados pessoais.
     */
    public static function record(int $inscricao_id, string $action, string $detail = ''): void
    {
        self::insert($inscricao_id, get_current_user_id(), $action, $detail);
    }

    private static function insert(
        int $inscricao_id,
        int $user_id,
        string $action,
        string $detail = '',
        string $old_value = '',
        string $new_value = ''
    ): void {
        global $wpdb;

        // Quem agiu, quando não é um usuário do WordPress.
        $actor = Ctx::actor();
        if ($actor === '' && $user_id === 0 && $inscricao_id > 0 && AuthManager::get_current_inscricao_id() === $inscricao_id) {
            $actor = 'Participante';
        }

        $wpdb->insert(
            $wpdb->prefix . self::TABLE,
            [
                'inscricao_id' => $inscricao_id,
                'user_id'      => $user_id,
                'actor'        => mb_substr($actor, 0, 190),
                'action'       => $action,
                'old_value'    => $old_value,
                'new_value'    => $new_value,
                'detail'       => mb_substr($detail, 0, 1000),
                'ip_address'   => self::request_ip(),
                'created_at'   => current_time('mysql'),
            ],
            ['%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
        );

        if ($wpdb->last_error) {
            error_log(sprintf(
                '[Festa\Audit\AuditLog] Falha ao inserir registro. inscricao_id=%d | erro=%s',
                $inscricao_id,
                $wpdb->last_error
            ));
        }
    }

    /**
     * Anonimização LGPD: troca pelo $placeholder os textos livres que a
     * Comissão digitou nas transições da inscrição (justificativa do
     * indeferimento, motivo da reabertura). Quem, quando e a mudança de
     * status continuam registrados.
     */
    public static function redact_free_text(int $inscricao_id, string $placeholder): void
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->query($wpdb->prepare(
            'UPDATE ' . $wpdb->prefix . self::TABLE . "
                SET detail = %s
              WHERE inscricao_id = %d AND action = 'status_transition'
                AND (detail LIKE %s OR detail LIKE %s)",
            $placeholder,
            $inscricao_id,
            $wpdb->esc_like('Justificativa:') . '%',
            $wpdb->esc_like('Motivo da reabertura:') . '%'
        ));
    }

    // -----------------------------------------------------------------------
    // Leitura
    // -----------------------------------------------------------------------

    /**
     * Registros mais recentes; de uma inscrição, quando informada.
     *
     * @return list<object>
     */
    public static function recent(int $limit = 500, int $inscricao_id = 0): array
    {
        global $wpdb;

        $table = $wpdb->prefix . self::TABLE;
        $where = $inscricao_id > 0 ? $wpdb->prepare('WHERE l.inscricao_id = %d', $inscricao_id) : '';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT l.id, l.inscricao_id, l.user_id, l.actor, l.action,
                        l.old_value, l.new_value, l.detail, l.ip_address, l.created_at,
                        u.user_login
                   FROM {$table} l
              LEFT JOIN {$wpdb->users} u ON u.ID = l.user_id
                  {$where}
               ORDER BY l.id DESC
                  LIMIT %d",
                $limit
            )
        );

        return is_array($rows) ? $rows : [];
    }

    /** Nome exibido de quem fez a ação de um registro. */
    public static function who(object $row): string
    {
        if (! empty($row->actor)) {
            return (string) $row->actor;
        }
        if ((int) $row->user_id === 0) {
            return 'Sistema';
        }
        // No portal, contas de fora do WordPress não veem os logins do
        // wp-admin (ajudariam a adivinhar senhas em wp-login.php).
        if (Ctx::is_portal()) {
            return 'Equipe (wp-admin)';
        }

        return ! empty($row->user_login) ? (string) $row->user_login : 'ID ' . (int) $row->user_id;
    }

    /** O que aconteceu em um registro, em uma linha de texto. */
    public static function what(object $row): string
    {
        if ($row->action === 'status_transition') {
            return trim(sprintf(
                'Status: %s → %s. %s',
                StateMachine::label((string) $row->old_value),
                StateMachine::label((string) $row->new_value),
                (string) $row->detail
            ));
        }

        return (string) $row->detail !== '' ? (string) $row->detail : self::action_label((string) $row->action);
    }

    // -----------------------------------------------------------------------
    // Página de auditoria
    // -----------------------------------------------------------------------

    public static function render_admin_page(): void
    {
        if (! Ctx::can('ver')) {
            Panel::no_access();
            return;
        }

        $logs = self::recent(500);
        ?>
        <div class="wrap festa-admin">
          <h1 class="wp-heading-inline">Festa dos Amigos 2026 — Logs de Auditoria</h1>
          <a href="<?php echo esc_url(Ctx::url('inscricoes')); ?>" class="page-title-action">Ver inscrições</a>
          <hr class="wp-header-end">

          <p class="festa-page-hint">
            Últimos 500 registros auditáveis, do mais recente para o mais antigo.
            IPs anonimizados conforme LGPD.
          </p>

          <table class="wp-list-table widefat fixed striped festa-table">
            <thead>
              <tr>
                <th class="festa-w150">Data/Hora</th>
                <th class="festa-w100">Protocolo</th>
                <th class="festa-w175">Usuário</th>
                <th class="festa-w130">IP Anônimo</th>
                <th class="festa-w175">Ação</th>
                <th>Detalhe</th>
              </tr>
            </thead>
            <tbody>
              <?php if (! $logs) : ?>
                <tr><td colspan="6">Nenhum registro de auditoria encontrado.</td></tr>
              <?php endif; ?>
              <?php foreach ($logs as $row) : ?>
                <tr>
                  <td><?php echo esc_html(mysql2date('d/m/Y H:i:s', (string) $row->created_at)); ?></td>
                  <td>
                    <?php
                    $pid = (int) $row->inscricao_id;
                    if ($pid > 0) {
                        printf(
                            '<a class="festa-num" href="%s">#%d</a>',
                            esc_url(Ctx::url('inscricoes', ['view' => $pid])),
                            $pid
                        );
                    } else {
                        echo '—';
                    }
                    ?>
                  </td>
                  <td><?php echo esc_html(self::who($row)); ?></td>
                  <td><code><?php echo esc_html((string) $row->ip_address); ?></code></td>
                  <td><?php echo esc_html(self::action_label((string) $row->action)); ?></td>
                  <td>
                    <?php
                    if ($row->action === 'status_transition') {
                        echo Panel::status_badge((string) $row->old_value) . ' → ' . Panel::status_badge((string) $row->new_value);
                        if ((string) $row->detail !== '') {
                            echo '<br><span class="festa-sub-text">' . esc_html((string) $row->detail) . '</span>';
                        }
                    } else {
                        echo esc_html((string) $row->detail);
                    }
                    ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>

          <p class="description">
            Exibindo <?php echo count($logs); ?> registro(s) — limite de 500 por consulta.
          </p>
        </div>
        <?php
    }

    // -----------------------------------------------------------------------
    // Helpers privados
    // -----------------------------------------------------------------------

    private static function action_label(string $action): string
    {
        return match ($action) {
            'status_transition'    => 'Transição de Status',
            'pre_cadastro'         => 'Pré-cadastro',
            'documento'            => 'Documento',
            'tenda'                => 'Número da tenda',
            'recibo_aprovado'      => 'Recibo aprovado',
            'colchoes_confirmados' => 'Colchões confirmados',
            'observacoes'          => 'Observações internas',
            'contato'              => 'Contato corrigido',
            'senha'                => 'Senha de acesso',
            'exclusao'             => 'Exclusão',
            'exportacao'           => 'Exportação',
            'configuracoes'        => 'Configurações',
            'lgpd'                 => 'Anonimização LGPD',
            default                => ucwords(str_replace('_', ' ', $action)),
        };
    }
}
