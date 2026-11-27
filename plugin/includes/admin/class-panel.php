<?php

declare(strict_types=1);

namespace Festa\Admin;

use Festa\Audit\AuditLog;
use Festa\Cpt\CptInscricao;
use Festa\Crypto\Crypto;
use Festa\Fields\Fields;
use Festa\Frontend\AuthManager;
use Festa\Pages\Pages;
use Festa\Pdf\Writer;
use Festa\Settings\Settings;
use Festa\Status\StateMachine;
use Festa\Status\Vagas;
use Festa\Upload\Handler;

/**
 * Painel de gerenciamento: lista, detalhe, observações, contato, senha do
 * participante, documentos e exportação CSV/PDF.
 *
 * As telas e ações passam pela fachada Ctx, então são as mesmas no wp-admin
 * e no portal externo. O status nunca é editado aqui: ele só muda pelas
 * ações de cada fase (ver AdminActions e AdminDefesa), que respeitam a
 * máquina de estados.
 *
 * LGPD: o CPF aparece sempre mascarado, inclusive nas exportações; a busca
 * por CPF é feita pelo hash.
 */
final class Panel
{
    private const PER_PAGE = 25;

    public static function boot_hooks(): void
    {
        $actions = [
            'festa_busca'      => 'search',
            'festa_notes'      => 'save_notes',
            'festa_contato'    => 'save_contato',
            'festa_senha'      => 'reset_password',
            'festa_anexar'     => 'attach',
            'festa_delete'     => 'delete',
            'festa_bulk'       => 'bulk',
            'festa_arquivo'    => 'serve_file',
            'festa_zip'        => 'zip',
            'festa_export'     => 'export_csv',
            'festa_export_pdf' => 'export_pdf',
        ];
        foreach ($actions as $action => $method) {
            add_action('admin_post_' . $action, [self::class, $method]);
        }

        add_action('admin_enqueue_scripts', [self::class, 'assets']);
    }

    public static function assets(string $hook): void
    {
        if (! str_contains($hook, 'festa')) {
            return;
        }
        wp_enqueue_style('festa-admin', FESTA_PLUGIN_URL . 'assets/css/festa-admin.css', [], FESTA_VERSION);
        wp_enqueue_script('festa-admin', FESTA_PLUGIN_URL . 'assets/js/festa-admin.js', [], FESTA_VERSION, true);
    }

    /** @param array<string, scalar> $args */
    private static function url(array $args = []): string
    {
        return Ctx::url('inscricoes', $args);
    }

    /** Aviso exibido no lugar de uma tela que o usuário não pode abrir. */
    public static function no_access(): void
    {
        echo '<div class="wrap festa-admin"><h1>Sem permissão</h1><div class="notice notice-error"><p>Seu usuário não tem permissão para acessar esta tela.</p></div></div>';
    }

    public static function status_badge(string $status): string
    {
        return '<span class="festa-badge festa-badge--' . esc_attr($status) . '">' . esc_html(StateMachine::label($status)) . '</span>';
    }

    /** Rótulo curto do status, para colunas estreitas (PDF). */
    private static function status_short(string $status): string
    {
        return match ($status) {
            StateMachine::STATUS_ANALISE      => 'Ag. Análise',
            StateMachine::STATUS_COMPROVANTES => 'Ag. Comprov.',
            StateMachine::STATUS_LISTA_ESPERA => 'Lista Espera',
            default                           => StateMachine::label($status),
        };
    }

    private static function yes_no(bool $value): string
    {
        return $value
            ? '<span class="festa-ok">&#10003; Sim</span>'
            : '<span class="festa-pending">&#10007; Não</span>';
    }

    // -----------------------------------------------------------------------
    // Roteamento
    // -----------------------------------------------------------------------

    public static function page(): void
    {
        if (! Ctx::can('ver')) {
            self::no_access();
            return;
        }

        $view = isset($_GET['view']) && is_scalar($_GET['view']) ? absint($_GET['view']) : 0;

        if ($view) {
            self::render_detail($view);
        } else {
            self::render_list();
        }
    }

    private static function notice(): void
    {
        $msg = isset($_GET['msg']) && is_string($_GET['msg']) ? sanitize_key($_GET['msg']) : '';
        $ok  = [
            'notes'      => 'Observações salvas.',
            'contato'    => 'Contato atualizado.',
            'nochange'   => 'Nenhuma alteração para salvar.',
            'anexado'    => 'Documento anexado.',
            'deleted'    => 'Inscrição excluída.',
            'bulk'       => 'Ação aplicada às inscrições selecionadas.',
            'deferido'   => 'Inscrição deferida. O participante foi avisado por e-mail.',
            'tenda'      => 'Número da tenda registrado. O participante foi avisado por e-mail.',
            'tenda_fix'  => 'Número da tenda corrigido.',
            'recibo'     => 'Recibo da tenda aprovado.',
            'colchoes'   => 'Entrega de colchões confirmada.',
            'concluido'  => 'Validação registrada e inscrição concluída. O Termo de Cessão foi gerado.',
            'indeferido' => 'Inscrição indeferida. O participante foi avisado por e-mail, com a justificativa.',
            'reaberto'   => 'Inscrição reaberta: voltou para “Aguardando Análise”. O participante foi avisado por e-mail.',
        ];
        $err = [
            'contato_erro'        => 'Não foi possível salvar: informe um e-mail válido e um telefone com DDD.',
            'status_erro'         => 'A inscrição não está mais na fase desta ação. Confira o status atual.',
            'tenda_erro'          => 'Informe o número da tenda.',
            'sem_arquivo'         => 'Escolha o arquivo antes de enviar.',
            'vagas_esgotadas'     => 'Não foi possível deferir: não há vagas restantes (limite de vagas atingido). Aumente o limite em Configurações ou aguarde uma vaga.',
            'vagas_ocupado'       => 'Outra ação sobre as vagas está em andamento neste momento. Aguarde alguns segundos e tente de novo.',
            'justificativa_curta' => sprintf('Nada foi feito: a justificativa do indeferimento é obrigatória (de %d a %d caracteres).', StateMachine::MOTIVO_MIN, StateMachine::MOTIVO_MAX),
            'motivo_curto'        => sprintf('Nada foi feito: o motivo da reabertura é obrigatório (de %d a %d caracteres).', StateMachine::MOTIVO_MIN, StateMachine::MOTIVO_MAX),
            'fila_vazia'          => 'A lista de espera está vazia.',
            'fila_sem_vaga'       => 'Não há vaga restante: ninguém foi chamado da lista de espera.',
            'rate_limit'          => 'Muitas ações seguidas. Aguarde alguns minutos e tente de novo.',
        ];

        if ($msg === 'chamado') {
            $chamado = isset($_GET['chamado']) && is_scalar($_GET['chamado']) ? absint($_GET['chamado']) : 0;
            echo '<div class="notice notice-success is-dismissible"><p>'
                . esc_html(sprintf('Inscrição #%d chamada da lista de espera: agora está aguardando análise. O participante foi avisado por e-mail.', $chamado))
                . ($chamado ? ' <a href="' . esc_url(self::url(['view' => $chamado])) . '">Abrir a inscrição</a>' : '')
                . '</p></div>';
        } elseif (isset($ok[$msg])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($ok[$msg]) . '</p></div>';
        } elseif (isset($err[$msg])) {
            echo '<div class="notice notice-error"><p>' . esc_html($err[$msg]) . '</p></div>';
        } elseif ($msg === 'erro') {
            // Mensagem específica de uma ação que falhou (ex.: arquivo recusado).
            $text = Ctx::flash_get('erro');
            if (is_string($text) && $text !== '') {
                echo '<div class="notice notice-error"><p>' . esc_html($text) . '</p></div>';
            }
        }
    }

    /** Volta para a inscrição com a mensagem de erro de uma ação. */
    public static function back_with_error(int $id, string $message): never
    {
        Ctx::flash_set('erro', $message);
        Ctx::redirect(self::url(['view' => $id, 'msg' => 'erro']));
    }

    // -----------------------------------------------------------------------
    // Filtros (lista, CSV e PDF usam os mesmos)
    // -----------------------------------------------------------------------

    /**
     * Filtros da URL. Uma busca por CPF chega aqui só como `cpf` = hash do
     * CPF (ver search()): o número nunca vai para o endereço.
     *
     * @return array{s: string, status: string, cpf: string}
     */
    private static function filters(): array
    {
        $get = static fn (string $key): string => isset($_GET[$key]) && is_string($_GET[$key]) ? wp_unslash($_GET[$key]) : '';

        $status = sanitize_key($get('status'));
        $cpf    = strtolower($get('cpf'));

        return [
            's'      => trim(sanitize_text_field($get('s'))),
            'status' => array_key_exists($status, StateMachine::labels()) ? $status : '',
            'cpf'    => preg_match('/^[a-f0-9]{64}$/', $cpf) ? $cpf : '',
        ];
    }

    /**
     * Busca da lista (POST): se o termo for um CPF válido, a lista é aberta
     * filtrada pelo hash dele — o CPF não aparece no endereço, no histórico
     * do navegador nem nos logs de acesso do servidor.
     */
    public static function search(): void
    {
        Ctx::require_post();
        Ctx::guard('ver', 'festa_busca');

        $s      = trim(sanitize_text_field(self::post_text('s')));
        $status = sanitize_key(self::post_text('status'));
        $args   = array_key_exists($status, StateMachine::labels()) ? ['status' => $status] : [];
        $digits = Fields::digits($s);

        if ($s !== '' && Fields::valid_cpf($digits)) {
            $args['cpf'] = Crypto::hash($digits);
        } elseif ($s !== '') {
            $args['s'] = $s;
        }

        Ctx::redirect(self::url($args));
    }

    /**
     * IDs das inscrições que atendem aos filtros.
     *
     * A busca cobre o protocolo, o CPF completo (comparado pelo hash — o CPF
     * nunca vai em texto para a consulta) e trechos de nome, grupo, e-mail,
     * telefone e tenda.
     *
     * @param array{s: string, status: string, cpf: string} $f
     * @return array{ids: list<int>, total: int}
     */
    private static function query(array $f, string $order = 'DESC', int $limit = 0, int $offset = 0): array
    {
        global $wpdb;

        $join   = '';
        $where  = ['p.post_type = %s', "p.post_status = 'publish'"];
        $params = [CptInscricao::POST_TYPE];

        if ($f['status'] !== '') {
            $join    .= " INNER JOIN {$wpdb->postmeta} st ON st.post_id = p.ID AND st.meta_key = '_status'";
            $where[]  = 'st.meta_value = %s';
            $params[] = $f['status'];
        }

        if ($f['cpf'] !== '') {
            $where[]  = "EXISTS (SELECT 1 FROM {$wpdb->postmeta} h WHERE h.post_id = p.ID AND h.meta_key = '_cpf_hash' AND h.meta_value = %s)";
            $params[] = $f['cpf'];
        }

        if ($f['s'] !== '') {
            $or     = [];
            $digits = Fields::digits($f['s']);

            if (Fields::valid_cpf($digits)) {
                $or[]     = "EXISTS (SELECT 1 FROM {$wpdb->postmeta} c WHERE c.post_id = p.ID AND c.meta_key = '_cpf_hash' AND c.meta_value = %s)";
                $params[] = Crypto::hash($digits);
            }
            // Protocolo: "123", "#123".
            if (preg_match('/^#?\s*0*(\d{1,9})$/', $f['s'], $m)) {
                $or[]     = 'p.ID = %d';
                $params[] = (int) $m[1];
            }
            $or[]     = "EXISTS (SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id = p.ID"
                . " AND m.meta_key IN ('_nome_responsavel', '_nome_grupo', '_email', '_telefone', '_numero_tenda')"
                . ' AND m.meta_value LIKE %s)';
            $params[] = '%' . $wpdb->esc_like($f['s']) . '%';

            $where[] = '(' . implode(' OR ', $or) . ')';
        }

        // A lista de espera sai na ordem da fila (data/hora da finalização).
        $order_by = 'p.ID ' . ($order === 'ASC' ? 'ASC' : 'DESC');
        if ($f['status'] === StateMachine::STATUS_LISTA_ESPERA) {
            $join    .= " LEFT JOIN {$wpdb->postmeta} fe ON fe.post_id = p.ID AND fe.meta_key = '_fila_em'";
            $order_by = '(fe.meta_value IS NULL) ASC, fe.meta_value ASC, p.ID ASC';
        }

        $from = "FROM {$wpdb->posts} p{$join} WHERE " . implode(' AND ', $where);

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery
        $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) {$from}", $params));

        $sql = "SELECT p.ID {$from} ORDER BY {$order_by}";
        if ($limit > 0) {
            $sql     .= ' LIMIT %d OFFSET %d';
            $params[] = $limit;
            $params[] = $offset;
        }
        $ids = array_map('intval', (array) $wpdb->get_col($wpdb->prepare($sql, $params)));
        // phpcs:enable

        // Um SELECT para os metas de todas as linhas, em vez de um por inscrição.
        if ($ids) {
            update_meta_cache('post', $ids);
            _prime_post_caches($ids, false, false);
        }

        return ['ids' => $ids, 'total' => $total];
    }

    /** @return array<string, int> status => quantidade (só contagens, para o resumo e o Dashboard) */
    public static function count_by_status(): array
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT st.meta_value AS status, COUNT(*) AS qty
               FROM {$wpdb->posts} p
         INNER JOIN {$wpdb->postmeta} st ON st.post_id = p.ID AND st.meta_key = '_status'
              WHERE p.post_type = %s AND p.post_status = 'publish'
           GROUP BY st.meta_value",
            CptInscricao::POST_TYPE
        ));

        $out = array_fill_keys(array_keys(StateMachine::labels()), 0);
        foreach ($rows as $row) {
            $out[(string) $row->status] = (int) $row->qty;
        }

        return $out;
    }

    /** Inscrições aguardando análise (selo do menu no wp-admin). */
    public static function count_pending(): int
    {
        return self::count_by_status()[StateMachine::STATUS_ANALISE] ?? 0;
    }

    // -----------------------------------------------------------------------
    // Lista
    // -----------------------------------------------------------------------

    private static function render_list(): void
    {
        $f     = self::filters();
        $paged = max(1, isset($_GET['paged']) && is_scalar($_GET['paged']) ? absint($_GET['paged']) : 1);

        $result    = self::query($f, 'DESC', self::PER_PAGE, ($paged - 1) * self::PER_PAGE);
        $total     = $result['total'];
        $pages     = max(1, (int) ceil($total / self::PER_PAGE));
        $by_status = self::count_by_status();
        $all       = array_sum($by_status);

        // O que este usuário pode fazer.
        $can_delete = Ctx::can('excluir');
        $can_export = Ctx::can('exportar');

        $export_args = array_filter($f);
        $csv_url     = Ctx::download_url('festa_export', $export_args, 'festa_export');
        $pdf_lista   = Ctx::download_url('festa_export_pdf', $export_args + ['layout' => 'lista'], 'festa_export_pdf');
        $pdf_fichas  = Ctx::download_url('festa_export_pdf', $export_args + ['layout' => 'fichas'], 'festa_export_pdf');
        $page_url    = Pages::url('pre');
        $labels      = StateMachine::labels();
        $is_filtered = $f['s'] !== '' || $f['status'] !== '' || $f['cpf'] !== '';
        $colspan     = $can_delete ? 10 : 9;
        $open        = Settings::is_open();
        $restantes   = Vagas::restantes();
        ?>
        <div class="wrap festa-admin">
          <h1 class="wp-heading-inline">Festa dos Amigos 2026 — Inscrições</h1>
          <?php if ($can_export) : ?>
            <a href="<?php echo esc_url($csv_url); ?>" class="page-title-action">Exportar CSV</a>
            <a href="<?php echo esc_url($pdf_lista); ?>" class="page-title-action">PDF — Lista</a>
            <a href="<?php echo esc_url($pdf_fichas); ?>" class="page-title-action">PDF — Fichas</a>
          <?php endif; ?>
          <a href="<?php echo esc_url(Ctx::url('auditoria')); ?>" class="page-title-action">Auditoria</a>
          <a href="<?php echo esc_url($page_url); ?>" class="page-title-action" target="_blank" rel="noopener">Ver pré-cadastro ↗</a>
          <hr class="wp-header-end">

          <?php self::notice(); ?>

          <p class="festa-page-hint">
            <span class="festa-dot <?php echo $open ? 'is-open' : 'is-closed'; ?>"></span>
            <?php echo $open ? 'Inscrições abertas' : 'Inscrições encerradas'; ?>
            <?php if (Ctx::can('config')) : ?>
              · <a href="<?php echo esc_url(Ctx::url('config')); ?>">Configurações</a>
            <?php endif; ?>
            · Pré-cadastro em <a href="<?php echo esc_url($page_url); ?>" target="_blank" rel="noopener"><code><?php echo esc_html($page_url); ?></code></a>
            <?php if ($is_filtered && $can_export) : ?>
              · <em>As exportações seguem os filtros aplicados.</em>
            <?php endif; ?>
          </p>

          <div class="festa-stats">
            <div class="festa-stat"><span class="festa-stat-num"><?php echo (int) $all; ?></span> Inscrições</div>
            <div class="festa-stat festa-stat--analise"><span class="festa-stat-num"><?php echo (int) $by_status[StateMachine::STATUS_ANALISE]; ?></span> Aguardando análise</div>
            <div class="festa-stat festa-stat--fila"><span class="festa-stat-num"><?php echo (int) $by_status[StateMachine::STATUS_LISTA_ESPERA]; ?></span> Na lista de espera</div>
            <div class="festa-stat festa-stat--deferido"><span class="festa-stat-num"><?php echo (int) $by_status[StateMachine::STATUS_DEFERIDO]; ?></span> Aguardando sorteio</div>
            <div class="festa-stat festa-stat--comprovantes"><span class="festa-stat-num"><?php echo (int) $by_status[StateMachine::STATUS_COMPROVANTES]; ?></span> Aguardando comprovantes</div>
            <div class="festa-stat festa-stat--ok"><span class="festa-stat-num"><?php echo (int) $by_status[StateMachine::STATUS_CONCLUIDO]; ?></span> Concluídas</div>
            <div class="festa-stat festa-stat--indeferido"><span class="festa-stat-num"><?php echo (int) $by_status[StateMachine::STATUS_INDEFERIDO]; ?></span> Indeferidas</div>
            <div class="festa-stat festa-stat--vagas"><span class="festa-stat-num"><?php echo $restantes === null ? '—' : (int) $restantes; ?></span>
              <?php echo esc_html($restantes === null ? 'Vagas: sem limite' : sprintf('Vagas restantes (limite %d)', Vagas::limite())); ?></div>
          </div>

          <?php self::render_fila_box((int) $by_status[StateMachine::STATUS_LISTA_ESPERA], $restantes, $f['status'] === StateMachine::STATUS_LISTA_ESPERA); ?>

          <ul class="subsubsub">
            <li><a href="<?php echo esc_url(self::url()); ?>" <?php echo $f['status'] === '' ? 'class="current"' : ''; ?>>Todas
              <span class="count">(<?php echo (int) $all; ?>)</span></a> |</li>
            <?php
            $i = 0;
            foreach ($labels as $key => $label) :
                $i++;
                ?>
              <li><a href="<?php echo esc_url(self::url(['status' => $key])); ?>"
                <?php echo $f['status'] === $key ? 'class="current"' : ''; ?>><?php echo esc_html($label); ?>
                <span class="count">(<?php echo (int) $by_status[$key]; ?>)</span></a><?php echo $i < count($labels) ? ' |' : ''; ?></li>
            <?php endforeach; ?>
          </ul>

          <?php // POST: um CPF digitado na busca não vai para o endereço nem para os logs do servidor. ?>
          <form method="post" class="festa-filters" action="<?php echo esc_url(Ctx::form_action()); ?>">
            <?php echo Ctx::form_fields('festa_busca', 'festa_busca'); ?>
            <?php if ($f['status'] !== '') : ?><input type="hidden" name="status" value="<?php echo esc_attr($f['status']); ?>"><?php endif; ?>
            <div class="tablenav top">
              <?php if ($is_filtered) : ?>
                <div class="alignleft actions">
                  <?php if ($f['cpf'] !== '') : ?><span class="festa-cpf-filter">Filtrando pelo CPF informado.</span><?php endif; ?>
                  <a class="button-link festa-clear-filters" href="<?php echo esc_url(self::url()); ?>">Limpar filtros</a>
                </div>
              <?php endif; ?>
              <p class="search-box">
                <label class="screen-reader-text" for="festa-search">Buscar inscrições</label>
                <input type="search" id="festa-search" name="s" value="<?php echo esc_attr($f['s']); ?>" placeholder="Protocolo, CPF completo, nome, grupo, e-mail…" autocomplete="off">
                <button class="button">Buscar</button>
              </p>
            </div>
          </form>

          <?php
          // Os formulários de ação ficam fora da tabela (HTML não permite
          // formulário dentro de formulário); caixas de seleção e botões se
          // ligam a eles pelo atributo form="…".
          ?>
          <div class="tablenav top festa-bulk-bar">
            <?php if ($can_delete) : ?>
              <form method="post" action="<?php echo esc_url(Ctx::form_action()); ?>" id="festa-bulk-form" class="alignleft actions bulkactions">
                <?php echo Ctx::form_fields('festa_bulk', 'festa_bulk'); ?>
                <label class="screen-reader-text" for="festa-bulk-action">Ações em massa</label>
                <select name="bulk_action" id="festa-bulk-action">
                  <option value="">Ações em massa</option>
                  <option value="delete">Excluir</option>
                </select>
                <button class="button action">Aplicar</button>
              </form>
              <form method="post" action="<?php echo esc_url(Ctx::form_action()); ?>" id="festa-row-delete" hidden>
                <?php echo Ctx::form_fields('festa_delete', 'festa_delete'); ?>
              </form>
            <?php endif; ?>
            <div class="tablenav-pages one-page"><span class="displaying-num"><?php echo esc_html(sprintf('%d %s', $total, $total === 1 ? 'item' : 'itens')); ?></span></div>
          </div>

          <table class="wp-list-table widefat fixed striped festa-table">
            <thead>
              <tr>
                <?php if ($can_delete) : ?>
                  <td class="manage-column column-cb check-column"><input type="checkbox" class="festa-check-all" aria-label="Selecionar todas"></td>
                <?php endif; ?>
                <th class="festa-w100">Protocolo</th>
                <th>Responsável</th>
                <th class="festa-w125">CPF</th>
                <th>Grupo</th>
                <th class="festa-w74">Tenda</th>
                <th class="festa-w175">Status</th>
                <th>Próximo passo</th>
                <th class="festa-w125">Criada em</th>
                <th class="festa-w125">Ações</th>
              </tr>
            </thead>
            <tbody>
            <?php if (! $result['ids']) : ?>
              <tr><td colspan="<?php echo (int) $colspan; ?>">Nenhuma inscrição encontrada.</td></tr>
            <?php endif; ?>
            <?php
            foreach ($result['ids'] as $id) :
                $r = CptInscricao::get($id);
                if (! $r) {
                    continue;
                }
                $view_url = self::url(['view' => $id]);
                ?>
              <tr>
                <?php if ($can_delete) : ?>
                  <th scope="row" class="check-column"><input type="checkbox" form="festa-bulk-form" name="ids[]" value="<?php echo (int) $id; ?>" aria-label="<?php echo esc_attr('Selecionar o protocolo ' . $id); ?>"></th>
                <?php endif; ?>
                <td><a href="<?php echo esc_url($view_url); ?>" class="festa-num">#<?php echo (int) $id; ?></a></td>
                <td>
                  <a href="<?php echo esc_url($view_url); ?>"><strong><?php echo esc_html($r['nome']); ?></strong></a>
                  <?php if ($r['email'] !== '') : ?>
                    <br><span class="festa-sub-text"><?php echo esc_html($r['email']); ?></span>
                  <?php endif; ?>
                </td>
                <td><?php echo esc_html($r['cpf']); ?></td>
                <td><?php echo $r['grupo'] !== '' ? esc_html($r['grupo']) : '—'; ?></td>
                <td><?php echo $r['tenda'] !== '' ? '<strong>' . esc_html($r['tenda']) . '</strong>' : '—'; ?></td>
                <td><?php echo self::status_badge($r['status']); ?></td>
                <td class="festa-sub-text"><?php echo esc_html(CptInscricao::next_step($r)); ?></td>
                <td><?php echo esc_html(mysql2date('d/m/Y H:i', $r['criada_em'])); ?></td>
                <td>
                  <a class="button button-small" href="<?php echo esc_url($view_url); ?>">Ver</a>
                  <?php if ($can_delete) : ?>
                    <button type="submit" class="button button-small festa-delete" form="festa-row-delete" name="id" value="<?php echo (int) $id; ?>"
                      data-festa-confirm="<?php echo esc_attr('Excluir definitivamente a inscrição #' . $id . ' e os seus documentos? Esta ação não pode ser desfeita.'); ?>">Excluir</button>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>

          <?php if ($pages > 1) : ?>
            <div class="tablenav"><div class="tablenav-pages">
              <?php
              echo paginate_links([
                  'base'    => add_query_arg('paged', '%#%', self::url(array_filter($f))),
                  'format'  => '',
                  'current' => $paged,
                  'total'   => $pages,
              ]);
              ?>
            </div></div>
          <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Lista de espera na tela da lista: tamanho da fila, vagas e o botão
     * "Chamar próximo da fila" (só com vaga restante).
     */
    private static function render_fila_box(int $na_fila, ?int $restantes, bool $filtrando): void
    {
        if ($na_fila === 0 && ! $filtrando) {
            return;
        }

        $proximo = $na_fila > 0 ? Vagas::proximo() : 0;
        ?>
        <div class="festa-box festa-fila-box">
          <p class="festa-fila-resumo">
            <strong>Lista de espera:</strong>
            <?php echo esc_html(sprintf('%d inscrição(ões) aguardando vaga', $na_fila)); ?>
            · <?php echo esc_html($restantes === null ? 'sem limite de vagas' : sprintf('vagas restantes: %d de %d', $restantes, Vagas::limite())); ?>
            <?php if (! $filtrando && $na_fila > 0) : ?>
              · <a href="<?php echo esc_url(self::url(['status' => StateMachine::STATUS_LISTA_ESPERA])); ?>">Ver a fila</a>
            <?php endif; ?>
          </p>
          <?php if (Ctx::can('analisar') && $na_fila > 0) : ?>
            <?php if ($restantes === 0) : ?>
              <p class="festa-fila-acao">
                <button type="button" class="button button-primary" disabled>Chamar próximo da fila</button>
                <span class="description">Sem vaga restante. Libere uma vaga ou aumente o limite em Configurações para chamar.</span>
              </p>
            <?php else : ?>
              <form method="post" action="<?php echo esc_url(Ctx::form_action()); ?>" class="festa-fila-acao"
                    data-festa-confirm="<?php echo esc_attr(sprintf('Chamar o próximo da fila (inscrição #%d)? Ela vai para “Aguardando Análise” e o participante é avisado por e-mail.', $proximo)); ?>">
                <?php echo Ctx::form_fields('festa_chamar_fila', 'festa_chamar_fila'); ?>
                <button type="submit" class="button button-primary">Chamar próximo da fila</button>
                <span class="description"><?php echo esc_html(sprintf('Próximo: inscrição #%d (a primeira a entrar na fila).', $proximo)); ?></span>
              </form>
            <?php endif; ?>
          <?php elseif ($na_fila === 0) : ?>
            <p class="description">Ninguém na lista de espera.</p>
          <?php endif; ?>
        </div>
        <?php
    }

    // -----------------------------------------------------------------------
    // Detalhe
    // -----------------------------------------------------------------------

    private static function render_detail(int $id): void
    {
        $row = CptInscricao::get($id);
        if (! $row) {
            echo '<div class="wrap festa-admin"><h1>Inscrição não encontrada</h1><p><a href="' . esc_url(self::url()) . '">← Voltar para a lista</a></p></div>';
            return;
        }

        $can_edit   = Ctx::can('editar');
        $can_delete = Ctx::can('excluir');
        $locked     = $row['status'] === StateMachine::STATUS_CONCLUIDO || $row['anonimizada'];
        $history    = AuditLog::recent(100, $id);
        $has_docs   = false;
        foreach (Fields::documents() as $doc) {
            $has_docs = $has_docs || Handler::path($id, $doc['meta']) !== '';
        }

        // Senha recém-gerada para o participante: mostrada uma única vez
        // (guardada cifrada até esta tela abrir).
        $flash = isset($_GET['senha']) ? Ctx::flash_get('senha') : null;
        if (! is_array($flash) || (int) ($flash['id'] ?? 0) !== $id) {
            $flash = null;
        } else {
            try {
                $flash['senha'] = Crypto::decrypt((string) $flash['senha']);
            } catch (\Throwable) {
                $flash = null;
            }
        }
        ?>
        <div class="wrap festa-admin">
          <h1 class="wp-heading-inline">Inscrição #<?php echo (int) $id; ?></h1>
          <?php if ($has_docs) : ?>
            <a href="<?php echo esc_url(Ctx::download_url('festa_zip', ['id' => $id], 'festa_zip_' . $id)); ?>" class="page-title-action">Baixar documentos (.zip)</a>
          <?php endif; ?>
          <a href="<?php echo esc_url(self::url()); ?>" class="page-title-action">← Voltar para a lista</a>
          <hr class="wp-header-end">

          <?php self::notice(); ?>

          <?php if ($flash) : ?>
            <div class="notice notice-warning festa-flash">
              <p><strong>Nova senha de acesso do participante</strong> — anote agora: ela não será mostrada de novo.</p>
              <p class="festa-flash-cred">Senha: <code class="festa-flash-pass"><?php echo esc_html((string) $flash['senha']); ?></code></p>
              <p>Entregue ao responsável por um canal seguro (pessoalmente ou por ligação). A senha anterior deixou de valer; no painel ele pode trocá-la por uma própria.</p>
            </div>
          <?php endif; ?>

          <?php if ($row['anonimizada']) : ?>
            <div class="notice notice-info"><p>Os dados pessoais e os documentos desta inscrição foram anonimizados (LGPD). O histórico administrativo foi preservado.</p></div>
          <?php endif; ?>

          <div class="festa-detail-layout">
            <div class="festa-detail-main">
              <div class="festa-detail-card">
                <div class="festa-detail-head">
                  <span class="festa-detail-num">#<?php echo (int) $id; ?></span>
                  <?php echo self::status_badge($row['status']); ?>
                  <span class="festa-detail-date">
                    Pré-cadastro em <?php echo esc_html(mysql2date('d/m/Y \à\s H:i', $row['criada_em'])); ?>
                  </span>
                </div>

                <p class="festa-next-step"><strong>Próximo passo:</strong> <?php echo esc_html(CptInscricao::next_step($row)); ?></p>

                <h2 class="festa-detail-section">Dados do responsável</h2>
                <table class="festa-detail-table">
                  <tr><th>Nome do responsável</th><td><?php echo self::value($row['nome']); ?></td></tr>
                  <tr><th>CPF</th><td><?php echo self::value($row['cpf']); ?> <span class="festa-sub-text">· mascarado por política LGPD</span></td></tr>
                  <tr><th>E-mail</th><td><?php echo $row['email'] !== '' ? '<a href="mailto:' . esc_attr($row['email']) . '">' . esc_html($row['email']) . '</a>' : self::value(''); ?></td></tr>
                  <tr>
                    <th>Telefone</th>
                    <td>
                      <?php
                      $digits = Fields::digits($row['telefone']);
                      if ($digits !== '' && ! $row['anonimizada']) {
                          echo '<a href="tel:' . esc_attr($digits) . '">' . esc_html($row['telefone']) . '</a>'
                              . ' · <a href="https://wa.me/55' . esc_attr($digits) . '" target="_blank" rel="noopener">WhatsApp</a>';
                      } else {
                          echo self::value($row['telefone']);
                      }
                      ?>
                    </td>
                  </tr>
                </table>

                <h2 class="festa-detail-section">Dados do grupo</h2>
                <table class="festa-detail-table">
                  <tr><th>Nome do grupo</th><td><?php echo self::value($row['grupo']); ?></td></tr>
                  <tr><th>Nº de participantes</th><td><?php echo self::value($row['qtd'] ? (string) $row['qtd'] : ''); ?></td></tr>
                  <tr><th>Tipo de cocção</th><td><?php echo self::value(Fields::coccao_label($row['coccao'])); ?></td></tr>
                  <tr><th>Espaço necessário</th><td><?php echo self::value(Fields::espaco_label($row['espaco'])); ?></td></tr>
                </table>

                <h2 class="festa-detail-section">Sorteio e validações finais</h2>
                <table class="festa-detail-table">
                  <tr><th>Número da tenda</th><td><?php echo $row['tenda'] !== '' ? '<strong class="festa-tenda">' . esc_html($row['tenda']) . '</strong>' : '<em class="festa-missing">Ainda não sorteada</em>'; ?></td></tr>
                  <tr><th>Recibo da tenda aprovado</th><td><?php echo self::yes_no($row['tenda_aprovada']); ?></td></tr>
                  <tr><th>Colchões confirmados</th><td><?php echo self::yes_no($row['colchoes']); ?></td></tr>
                </table>

                <h2 class="festa-detail-section">Documentos</h2>
                <table class="festa-detail-table">
                  <?php foreach (Fields::documents() as $key => $doc) : ?>
                    <tr>
                      <th><?php echo esc_html($doc['label']); ?></th>
                      <td>
                        <?php
                        echo self::file_cell($id, $key);
                        if ($can_edit && ! $locked && self::can_attach($key, $row)) {
                            self::attach_form($id, $key, Handler::path($id, $doc['meta']) !== '');
                        }
                        ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </table>

                <h2 class="festa-detail-section">Aceite dos termos (edital e LGPD)</h2>
                <table class="festa-detail-table">
                  <tr>
                    <th>Aceite</th>
                    <td>
                      <?php if ($row['aceite_em'] !== '') : ?>
                        <strong>Aceito em <?php echo esc_html(mysql2date('d/m/Y \à\s H:i', $row['aceite_em'])); ?></strong>
                        <br><span class="festa-sub-text"><?php echo esc_html(Fields::aceite_texto()); ?></span>
                      <?php elseif ($row['status'] === StateMachine::STATUS_RASCUNHO) : ?>
                        <em class="festa-missing">Ainda não registrado (inscrição não finalizada)</em>
                      <?php else : ?>
                        <em class="festa-missing">Data não registrada (o aceite foi marcado no formulário de finalização)</em>
                      <?php endif; ?>
                    </td>
                  </tr>
                </table>
              </div>
            </div>

            <aside class="festa-detail-side">
              <?php AdminActions::render_box($row); ?>

              <div class="festa-box">
                <h2>Observações internas</h2>
                <?php if ($can_edit) : ?>
                  <p class="description">Visíveis só para a equipe. Ex.: pendências, contato feito, combinado com o grupo.</p>
                  <form method="post" action="<?php echo esc_url(Ctx::form_action()); ?>">
                    <?php echo Ctx::form_fields('festa_notes', 'festa_notes_' . $id); ?>
                    <input type="hidden" name="id" value="<?php echo (int) $id; ?>">
                    <textarea name="observacoes" rows="5" class="widefat"><?php echo esc_textarea($row['observacoes']); ?></textarea>
                    <p><button class="button">Salvar observações</button></p>
                  </form>
                <?php elseif (trim($row['observacoes']) !== '') : ?>
                  <p><?php echo nl2br(esc_html($row['observacoes'])); ?></p>
                <?php else : ?>
                  <p class="description">Nenhuma observação.</p>
                <?php endif; ?>
              </div>

              <?php if ($can_edit && ! $locked && $row['status'] !== StateMachine::STATUS_RASCUNHO) : ?>
                <div class="festa-box">
                  <h2>Corrigir contato</h2>
                  <p class="description">Os avisos de cada etapa vão para este e-mail. Os demais dados são os da ficha assinada e não são editados aqui.</p>
                  <form method="post" action="<?php echo esc_url(Ctx::form_action()); ?>" class="festa-stack">
                    <?php echo Ctx::form_fields('festa_contato', 'festa_contato_' . $id); ?>
                    <input type="hidden" name="id" value="<?php echo (int) $id; ?>">
                    <label for="festa-contato-email">E-mail</label>
                    <input type="email" id="festa-contato-email" name="email" class="widefat" value="<?php echo esc_attr($row['email']); ?>" required>
                    <label for="festa-contato-tel">Telefone</label>
                    <input type="text" id="festa-contato-tel" name="telefone" class="widefat" value="<?php echo esc_attr($row['telefone']); ?>" required>
                    <p><button class="button">Salvar contato</button></p>
                  </form>
                </div>
              <?php endif; ?>

              <?php if ($can_edit && ! $row['anonimizada']) : ?>
                <div class="festa-box">
                  <h2>Acesso do participante</h2>
                  <p class="description">Não existe “esqueci minha senha”: quem perdeu a senha recebe uma nova por aqui. A anterior deixa de valer.</p>
                  <form method="post" action="<?php echo esc_url(Ctx::form_action()); ?>"
                        data-festa-confirm="Gerar uma nova senha de acesso para este participante? A senha atual deixa de valer.">
                    <?php echo Ctx::form_fields('festa_senha', 'festa_senha_' . $id); ?>
                    <input type="hidden" name="id" value="<?php echo (int) $id; ?>">
                    <button class="button">Gerar nova senha</button>
                  </form>
                </div>
              <?php endif; ?>

              <div class="festa-box">
                <h2>Histórico</h2>
                <?php if ($history) : ?>
                  <ol class="festa-history">
                    <?php foreach ($history as $h) : ?>
                      <li>
                        <span class="festa-history-when"><?php echo esc_html(mysql2date('d/m/Y H:i', (string) $h->created_at) . ' · ' . AuditLog::who($h)); ?></span>
                        <?php echo esc_html(AuditLog::what($h)); ?>
                      </li>
                    <?php endforeach; ?>
                  </ol>
                <?php else : ?>
                  <p class="description">Sem registros.</p>
                <?php endif; ?>
              </div>

              <?php if ($can_delete) : ?>
                <form method="post" action="<?php echo esc_url(Ctx::form_action()); ?>"
                      data-festa-confirm="Excluir definitivamente esta inscrição e os seus documentos? Esta ação não pode ser desfeita.">
                  <?php echo Ctx::form_fields('festa_delete', 'festa_delete'); ?>
                  <input type="hidden" name="id" value="<?php echo (int) $id; ?>">
                  <button type="submit" class="button festa-delete">Excluir inscrição</button>
                </form>
              <?php endif; ?>
            </aside>
          </div>
        </div>
        <?php
    }

    private static function value(string $value): string
    {
        return trim($value) !== '' ? nl2br(esc_html($value)) : '<em class="festa-missing">Não informado</em>';
    }

    // -----------------------------------------------------------------------
    // Documentos
    // -----------------------------------------------------------------------

    public static function file_url(int $id, string $key, bool $download): string
    {
        $args = ['id' => $id, 'doc' => $key];
        if ($download) {
            $args['dl'] = 1;
        }

        return Ctx::download_url('festa_arquivo', $args, 'festa_arquivo_' . $id);
    }

    private static function file_cell(int $id, string $key): string
    {
        $doc  = Fields::documents()[$key];
        $info = Handler::info($id, $doc['meta']);

        if ($info === null) {
            return '<span class="festa-missing">' . ($doc['origem'] === 'sistema' ? 'Ainda não gerado' : 'Não enviado') . '</span>';
        }
        if ($info['path'] === '') {
            return '<span class="festa-missing">Arquivo não encontrado no servidor</span>';
        }

        $view = self::file_url($id, $key, false);
        $dl   = self::file_url($id, $key, true);
        $tag  = strtoupper($info['ext'] === 'html' ? 'doc' : $info['ext']);

        $html = '<div class="festa-doc">';
        if (Handler::is_previewable($info['ext'])) {
            $html .= '<a href="' . esc_url($view) . '" target="_blank" rel="noopener"><img class="festa-doc-thumb" src="' . esc_url($view) . '" alt="" loading="lazy"></a>';
        } else {
            $html .= '<span class="festa-doc-ico' . ($info['ext'] === 'pdf' ? ' is-pdf' : '') . '">' . esc_html($tag) . '</span>';
        }
        $html .= '<div class="festa-doc-meta">';
        $html .= '<strong>' . esc_html($info['name']) . '</strong>';
        $html .= '<span>' . esc_html(
            Handler::human_size($info['size'])
            . ($info['uploaded_at'] !== '' ? ' · ' . ($doc['origem'] === 'sistema' ? 'gerado' : 'enviado') . ' em ' . mysql2date('d/m/Y H:i', $info['uploaded_at']) : '')
        ) . '</span>';
        $html .= '<span><a href="' . esc_url($view) . '" target="_blank" rel="noopener">Abrir</a> · <a href="' . esc_url($dl) . '">Baixar</a></span>';
        $html .= '</div></div>';

        return $html;
    }

    /**
     * A equipe pode anexar este documento em nome do participante?
     * Vale a mesma regra do envio pelo painel dele: cada documento na fase
     * em que faz parte do fluxo. O deferimento entra pela ação de deferir.
     *
     * @param array<string, mixed> $row
     */
    private static function can_attach(string $key, array $row): bool
    {
        return match ($key) {
            'identidade', 'comprovante_residencia' => true,
            'ficha_assinada' => in_array($row['status'], [StateMachine::STATUS_ANALISE, StateMachine::STATUS_LISTA_ESPERA], true),
            'recibo_tenda'   => $row['status'] === StateMachine::STATUS_COMPROVANTES && ! $row['tenda_aprovada'],
            default          => false,
        };
    }

    private static function attach_form(int $id, string $key, bool $has): void
    {
        $accept = Fields::documents()[$key]['accept'];
        ?>
        <form method="post" enctype="multipart/form-data" action="<?php echo esc_url(Ctx::form_action()); ?>" class="festa-replace">
          <?php echo Ctx::form_fields('festa_anexar', 'festa_anexar_' . $id); ?>
          <input type="hidden" name="id" value="<?php echo (int) $id; ?>">
          <input type="hidden" name="doc" value="<?php echo esc_attr($key); ?>">
          <label><?php echo $has ? 'Substituir por: ' : 'Anexar (entregue em mãos): '; ?>
            <input type="file" name="documento" accept="<?php echo esc_attr(Handler::accept_attr($accept)); ?>" required>
          </label>
          <button class="button button-small">Enviar</button>
          <span class="description"><?php echo esc_html(Handler::accept_hint($accept) . ' · até ' . Handler::human_size(Handler::max_bytes())); ?></span>
        </form>
        <?php
    }

    // -----------------------------------------------------------------------
    // Ações rápidas
    // -----------------------------------------------------------------------

    public static function post_id(): int
    {
        return isset($_REQUEST['id']) && is_scalar($_REQUEST['id']) ? absint($_REQUEST['id']) : 0;
    }

    private static function post_text(string $key): string
    {
        return isset($_POST[$key]) && is_string($_POST[$key]) ? wp_unslash($_POST[$key]) : '';
    }

    public static function save_notes(): void
    {
        Ctx::require_post();
        $id = self::post_id();
        Ctx::guard('editar', 'festa_notes_' . $id);

        $obs = sanitize_textarea_field(self::post_text('observacoes'));

        if (CptInscricao::exists($id) && $obs !== (string) get_post_meta($id, '_observacoes', true)) {
            update_post_meta($id, '_observacoes', $obs);
            AuditLog::record($id, 'observacoes', 'Observações internas atualizadas.');
        }

        Ctx::redirect(self::url(['view' => $id, 'msg' => 'notes']));
    }

    public static function save_contato(): void
    {
        Ctx::require_post();
        $id = self::post_id();
        Ctx::guard('editar', 'festa_contato_' . $id);

        $row = CptInscricao::get($id);
        if (! $row) {
            Ctx::stop('Inscrição não encontrada.', 404);
        }
        // Concluída, a inscrição é somente leitura.
        if ($row['status'] === StateMachine::STATUS_CONCLUIDO || $row['status'] === StateMachine::STATUS_RASCUNHO) {
            Ctx::redirect(self::url(['view' => $id, 'msg' => 'status_erro']));
        }

        $email    = sanitize_email(self::post_text('email'));
        $telefone = sanitize_text_field(self::post_text('telefone'));

        if (! is_email($email) || ! Fields::valid_phone($telefone)) {
            Ctx::redirect(self::url(['view' => $id, 'msg' => 'contato_erro']));
        }

        $telefone = Fields::format_phone($telefone);
        $changed  = [];
        if ($email !== $row['email']) {
            update_post_meta($id, '_email', $email);
            $changed[] = 'e-mail';
        }
        if ($telefone !== $row['telefone']) {
            update_post_meta($id, '_telefone', $telefone);
            $changed[] = 'telefone';
        }

        if (! $changed) {
            Ctx::redirect(self::url(['view' => $id, 'msg' => 'nochange']));
        }

        AuditLog::record($id, 'contato', 'Contato corrigido pela equipe: ' . implode(' e ', $changed) . '.');
        Ctx::redirect(self::url(['view' => $id, 'msg' => 'contato']));
    }

    /**
     * Gera uma nova senha de acesso para o participante (provisória) e
     * destrava a inscrição se ela estiver bloqueada por tentativas erradas.
     */
    public static function reset_password(): void
    {
        Ctx::require_post();
        $id = self::post_id();
        Ctx::guard('editar', 'festa_senha_' . $id);

        if (! CptInscricao::exists($id)) {
            Ctx::stop('Inscrição não encontrada.', 404);
        }

        // A senha nova encerra as sessões abertas com a anterior (set_password).
        $senha = AuthManager::generate_password();
        AuthManager::set_password($id, $senha, true);
        AuthManager::clear_failures((string) get_post_meta($id, '_cpf_hash', true));

        // Guardada só até a próxima tela, para ser mostrada uma única vez —
        // cifrada: o banco nunca tem a senha em claro.
        Ctx::flash_set('senha', ['id' => $id, 'senha' => Crypto::encrypt($senha)]);
        AuditLog::record($id, 'senha', 'Nova senha de acesso gerada pela equipe.');

        Ctx::redirect(self::url(['view' => $id, 'senha' => 1]));
    }

    public static function attach(): void
    {
        Ctx::require_post();
        $id = self::post_id();
        Ctx::guard('editar', 'festa_anexar_' . $id);

        $row = CptInscricao::get($id);
        $key = sanitize_key(self::post_text('doc'));
        if (! $row) {
            Ctx::stop('Inscrição não encontrada.', 404);
        }
        if ($row['status'] === StateMachine::STATUS_CONCLUIDO || $row['anonimizada'] || ! self::can_attach($key, $row)) {
            Ctx::redirect(self::url(['view' => $id, 'msg' => 'status_erro']));
        }

        $file = Handler::uploaded('documento');
        if ($file === null) {
            Ctx::redirect(self::url(['view' => $id, 'msg' => 'sem_arquivo']));
        }

        try {
            Handler::handle_upload($file, $id, Fields::documents()[$key]['meta']);
        } catch (\RuntimeException $e) {
            self::back_with_error($id, $e->getMessage());
        }

        AuditLog::record($id, 'documento', 'Documento anexado pela equipe: ' . Fields::document_label($key) . '.');
        Ctx::redirect(self::url(['view' => $id, 'msg' => 'anexado']));
    }

    private static function delete_row(int $id): void
    {
        if (CptInscricao::exists($id)) {
            AuditLog::record($id, 'exclusao', 'Inscrição excluída, com os seus documentos.');
            CptInscricao::delete($id);
        }
    }

    public static function delete(): void
    {
        Ctx::require_post();
        Ctx::guard('excluir', 'festa_delete');
        self::delete_row(self::post_id());
        Ctx::redirect(self::url(['msg' => 'deleted']));
    }

    public static function bulk(): void
    {
        Ctx::require_post();
        Ctx::guard('excluir', 'festa_bulk');

        $ids    = isset($_POST['ids']) && is_array($_POST['ids']) ? array_filter(array_map('absint', $_POST['ids'])) : [];
        $action = sanitize_key(self::post_text('bulk_action'));

        if ($ids && $action === 'delete') {
            foreach ($ids as $id) {
                self::delete_row($id);
            }
        }

        Ctx::redirect(self::url(['msg' => 'bulk']));
    }

    public static function serve_file(): void
    {
        $id  = self::post_id();
        $key = isset($_GET['doc']) && is_string($_GET['doc']) ? sanitize_key($_GET['doc']) : '';

        // A Defesa Civil não vê as inscrições, mas abre o comprovante de
        // doação que ela mesma emite (precisa imprimir, assinar e carimbar) —
        // só de uma inscrição que acabou de encontrar pela busca por CPF.
        $perm = $key === 'comprovante_doacao' && ! Ctx::can('ver') ? 'colchoes' : 'ver';
        Ctx::guard($perm, 'festa_arquivo_' . $id);
        if ($perm === 'colchoes' && ! AdminDefesa::found_recently($id)) {
            Ctx::stop('Busque a inscrição pelo CPF do responsável para abrir o comprovante.', 403);
        }

        $doc  = Fields::document($key);
        $info = $doc && CptInscricao::exists($id) ? Handler::info($id, $doc['meta']) : null;
        if ($info === null || $info['path'] === '') {
            Ctx::stop('Documento não encontrado.', 404);
        }

        Handler::send(
            $info['path'],
            $info['mime'],
            Handler::download_name($id, $doc['meta'], $info['ext']),
            ! empty($_GET['dl'])
        );
    }

    public static function zip(): void
    {
        $id = self::post_id();
        Ctx::guard('ver', 'festa_zip_' . $id);

        if (! class_exists(\ZipArchive::class)) {
            Ctx::stop('O servidor não tem suporte a arquivos ZIP. Baixe os documentos um a um na tela da inscrição.', 500);
        }
        if (! CptInscricao::exists($id)) {
            Ctx::stop('Inscrição não encontrada.', 404);
        }

        $files = [];
        foreach (Fields::documents() as $doc) {
            $info = Handler::info($id, $doc['meta']);
            if ($info !== null && $info['path'] !== '') {
                $files[Handler::download_name($id, $doc['meta'], $info['ext'])] = $info['path'];
            }
        }
        if (! $files) {
            Ctx::stop('Nenhum documento nesta inscrição.', 404);
        }

        // wp_tempnam() só é carregada automaticamente dentro do wp-admin.
        if (! function_exists('wp_tempnam')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        $tmp = wp_tempnam('festa-' . $id);
        $zip = new \ZipArchive();
        if ($zip->open($tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            Ctx::stop('Não foi possível montar o arquivo ZIP.', 500);
        }
        // addFromString em vez de addFile: addFile só lê o arquivo no close()
        // e pula em silêncio o que não conseguir abrir. Aqui cada documento
        // é lido e conferido.
        $faltando = [];
        foreach ($files as $name => $path) {
            $content = file_get_contents($path);
            if ($content === false) {
                $faltando[] = $name;
                continue;
            }
            $zip->addFromString($name, $content);
        }
        if ($faltando) {
            $zip->addFromString('LEIA-ME - documentos nao encontrados.txt', "Não foi possível ler no servidor:\r\n- " . implode("\r\n- ", $faltando) . "\r\n");
        }
        $zip->close();

        register_shutdown_function(static function () use ($tmp): void {
            wp_delete_file($tmp);
        });

        AuditLog::record($id, 'exportacao', 'Documentos baixados em ZIP.');
        Handler::send($tmp, 'application/zip', 'inscricao-' . str_pad((string) $id, 4, '0', STR_PAD_LEFT) . '.zip', true);
    }

    // -----------------------------------------------------------------------
    // Exportação CSV
    // -----------------------------------------------------------------------

    /**
     * Inscrições filtradas, em ordem de protocolo.
     *
     * @return list<array<string, mixed>>
     */
    private static function filtered_rows(): array
    {
        $rows = [];
        foreach (self::query(self::filters(), 'ASC')['ids'] as $id) {
            $row = CptInscricao::get($id);
            if ($row) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    public static function export_csv(): void
    {
        Ctx::guard('exportar', 'festa_export');

        $rows = self::filtered_rows();
        AuditLog::record(0, 'exportacao', sprintf('Inscrições exportadas em CSV (%d).', count($rows)));

        $header = [
            'Protocolo', 'Pré-cadastro em', 'Status', 'Próximo passo', 'Responsável', 'CPF (mascarado)', 'E-mail', 'Telefone',
            'Grupo', 'Nº de participantes', 'Cocção', 'Espaço', 'Tenda', 'Recibo aprovado', 'Colchões confirmados',
        ];
        foreach (Fields::documents() as $doc) {
            $header[] = 'Doc.: ' . $doc['label'];
        }
        $header[] = 'Aceite dos termos (edital e LGPD)';
        $header[] = 'Lista de espera: posição';
        $header[] = 'Lista de espera: desde';
        $header[] = 'Justificativa do indeferimento';
        $header[] = 'Observações internas';

        nocache_headers();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename=festa-dos-amigos-inscricoes-' . gmdate('Y-m-d') . '.csv');

        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF"); // BOM para o Excel.
        fputcsv($out, $header, ';', '"', '\\');

        foreach ($rows as $row) {
            $line = [
                (string) $row['id'],
                mysql2date('d/m/Y H:i', $row['criada_em']),
                StateMachine::label($row['status']),
                CptInscricao::next_step($row),
                $row['nome'],
                $row['cpf'],
                $row['email'],
                $row['telefone'],
                $row['grupo'],
                $row['qtd'] ? (string) $row['qtd'] : '',
                Fields::coccao_label($row['coccao']),
                Fields::espaco_label($row['espaco']),
                $row['tenda'],
                $row['tenda_aprovada'] ? 'Sim' : 'Não',
                $row['colchoes'] ? 'Sim' : 'Não',
            ];
            foreach (Fields::documents() as $doc) {
                $sent   = Handler::path($row['id'], $doc['meta']) !== '';
                $line[] = $doc['origem'] === 'sistema' ? ($sent ? 'Gerado' : '') : ($sent ? 'Enviado' : 'Não enviado');
            }
            $line[] = $row['aceite_em'] !== '' ? mysql2date('d/m/Y H:i', $row['aceite_em']) : 'Não registrado';
            $na_fila = $row['status'] === StateMachine::STATUS_LISTA_ESPERA;
            $line[] = $na_fila && Vagas::posicao($row['id']) > 0 ? (string) Vagas::posicao($row['id']) : '';
            $line[] = $na_fila && $row['fila_em'] !== '' ? mysql2date('d/m/Y H:i', $row['fila_em']) : '';
            $line[] = $row['status'] === StateMachine::STATUS_INDEFERIDO ? $row['indeferimento'] : '';
            $line[] = $row['observacoes'];

            // Planilhas tratam células iniciadas por = + - @ como fórmula.
            $line = array_map(
                static fn (string $cell): string => preg_match('/^[=+\-@\t\r]/', $cell) ? "'" . $cell : $cell,
                $line
            );
            fputcsv($out, $line, ';', '"', '\\');
        }

        fclose($out);
        exit;
    }

    // -----------------------------------------------------------------------
    // Exportação PDF
    // -----------------------------------------------------------------------

    public static function export_pdf(): void
    {
        Ctx::guard('exportar', 'festa_export_pdf');

        $layout = isset($_GET['layout']) && $_GET['layout'] === 'fichas' ? 'fichas' : 'lista';
        $rows   = self::filtered_rows();
        AuditLog::record(0, 'exportacao', sprintf('Inscrições exportadas em PDF — %s (%d).', $layout, count($rows)));

        $pdf = new Writer();
        if ($layout === 'fichas') {
            self::pdf_fichas($pdf, $rows);
        } else {
            self::pdf_lista($pdf, $rows, self::filters());
        }
        $doc = $pdf->output();

        nocache_headers();
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename=festa-dos-amigos-' . $layout . '-' . gmdate('Y-m-d') . '.pdf');
        header('Content-Length: ' . strlen($doc));
        echo $doc; // binário PDF.
        exit;
    }

    /** @param array{s: string, status: string, cpf: string} $f */
    private static function filters_label(array $f): string
    {
        $parts = [];
        if ($f['status'] !== '') {
            $parts[] = 'status: ' . StateMachine::label($f['status']);
        }
        if ($f['cpf'] !== '') {
            $parts[] = 'busca por CPF';
        }
        if ($f['s'] !== '') {
            // Um CPF usado na busca não vai para o relatório.
            $parts[] = Fields::valid_cpf($f['s']) ? 'busca por CPF' : 'busca: "' . $f['s'] . '"';
        }

        return $parts ? ' — filtros: ' . implode(', ', $parts) : '';
    }

    private const PDF_HEADING = 'Festa dos Amigos 2026 — Edital 001/2026/SMDES';

    /**
     * Lista: uma linha por inscrição, em ordem de protocolo.
     *
     * @param list<array<string, mixed>>       $rows
     * @param array{s: string, status: string} $f
     */
    private static function pdf_lista(Writer $pdf, array $rows, array $f): void
    {
        $widths = [40.0, 112.0, 104.0, 84.0, 78.0, 34.0, 47.0];
        $header = ['Prot.', 'Responsável', 'Grupo', 'CPF', 'Telefone', 'Tenda', 'Status'];

        $draw_header = static function () use ($pdf, $widths, $header, $rows, $f): void {
            $pdf->line(self::PDF_HEADING, 9, false, 0, 0.45);
            $pdf->spacer(3);
            $pdf->line('Lista de inscrições (ordem de protocolo)', 14, true);
            foreach (Writer::wrap(sprintf('%d inscrição(ões)%s — emitido em %s', count($rows), self::filters_label($f), wp_date('d/m/Y H:i')), 110) as $l) {
                $pdf->line($l, 9.5, false, 0, 0.35);
            }
            $pdf->spacer(10);
            $pdf->grid_row($header, $widths, true, true);
        };

        $draw_header();

        if (! $rows) {
            $pdf->spacer(6);
            $pdf->line('Nenhuma inscrição encontrada.');
            return;
        }

        foreach ($rows as $row) {
            if (! $pdf->has_room(3)) {
                $pdf->add_page();
                $draw_header();
            }
            $pdf->grid_row(
                [
                    '#' . $row['id'],
                    $row['nome'],
                    $row['grupo'],
                    $row['cpf'],
                    $row['telefone'],
                    $row['tenda'],
                    self::status_short($row['status']),
                ],
                $widths
            );
        }
    }

    /**
     * Fichas: uma inscrição por página, com todas as respostas e a situação
     * de cada documento.
     *
     * @param list<array<string, mixed>> $rows
     */
    private static function pdf_fichas(Writer $pdf, array $rows): void
    {
        if (! $rows) {
            $pdf->line(self::PDF_HEADING, 9, false, 0, 0.45);
            $pdf->spacer(4);
            $pdf->line('Nenhuma inscrição encontrada.');
            return;
        }

        $section = static function (string $title) use ($pdf): void {
            $pdf->spacer(10);
            $pdf->line($title, 10, true, 0, 0.2);
            $pdf->spacer(4);
            $pdf->hline();
        };

        foreach ($rows as $i => $row) {
            if ($i > 0) {
                $pdf->add_page();
            }

            $pdf->line(self::PDF_HEADING, 9, false, 0, 0.45);
            $pdf->spacer(3);
            foreach (Writer::wrap(sprintf('Inscrição #%d — %s', $row['id'], $row['grupo'] !== '' ? $row['grupo'] : $row['nome']), 55) as $l) {
                $pdf->line($l, 14, true);
            }
            $pdf->line(
                sprintf('Status: %s — Pré-cadastro em %s', StateMachine::label($row['status']), mysql2date('d/m/Y \à\s H:i', $row['criada_em'])),
                9.5,
                false,
                0,
                0.35
            );

            $section('DADOS DO RESPONSÁVEL');
            $pdf->table_row('Nome do responsável', $row['nome']);
            $pdf->table_row('CPF (mascarado)', $row['cpf']);
            $pdf->table_row('E-mail', $row['email']);
            $pdf->table_row('Telefone', $row['telefone']);

            $section('DADOS DO GRUPO');
            $pdf->table_row('Nome do grupo', $row['grupo']);
            $pdf->table_row('Nº de participantes', $row['qtd'] ? (string) $row['qtd'] : '');
            $pdf->table_row('Tipo de cocção', Fields::coccao_label($row['coccao']));
            $pdf->table_row('Espaço necessário', Fields::espaco_label($row['espaco']));

            $section('SORTEIO E VALIDAÇÕES FINAIS');
            $pdf->table_row('Número da tenda', $row['tenda']);
            $pdf->table_row('Recibo da tenda aprovado', $row['tenda_aprovada'] ? 'Sim' : 'Não');
            $pdf->table_row('Colchões confirmados', $row['colchoes'] ? 'Sim' : 'Não');

            $section('DOCUMENTOS');
            foreach (Fields::documents() as $doc) {
                $sent = Handler::path($row['id'], $doc['meta']) !== '';
                if ($doc['origem'] === 'sistema') {
                    $state = $sent ? 'Gerado' : '—';
                } else {
                    $state = $sent ? 'Enviado' : 'NÃO ENVIADO';
                }
                $pdf->table_row($doc['label'], $state);
            }

            $section('ACEITE DOS TERMOS (EDITAL E LGPD)');
            $pdf->table_row('Aceite', $row['aceite_em'] !== '' ? 'Aceito em ' . mysql2date('d/m/Y H:i', $row['aceite_em']) : 'Não registrado');

            if ($row['status'] === StateMachine::STATUS_LISTA_ESPERA) {
                $section('LISTA DE ESPERA');
                $pdf->table_row('Posição na fila', Vagas::posicao($row['id']) > 0 ? Vagas::posicao($row['id']) . 'º' : '—');
                $pdf->table_row('Na fila desde', $row['fila_em'] !== '' ? mysql2date('d/m/Y H:i', $row['fila_em']) : '—');
            }

            if ($row['status'] === StateMachine::STATUS_INDEFERIDO) {
                $pdf->spacer(10);
                $pdf->line('JUSTIFICATIVA DO INDEFERIMENTO', 10, true, 0, 0.2);
                $pdf->spacer(4);
                foreach (Writer::wrap($row['indeferimento'] !== '' ? $row['indeferimento'] : '—', 95) as $l) {
                    $pdf->line($l);
                }
            }

            if (trim($row['observacoes']) !== '') {
                $pdf->spacer(10);
                $pdf->line('OBSERVAÇÕES INTERNAS', 10, true, 0, 0.2);
                $pdf->spacer(4);
                foreach (Writer::wrap($row['observacoes'], 95) as $l) {
                    $pdf->line($l);
                }
            }
        }
    }
}
