<?php
/**
 * Prepara e desmonta o ambiente da suíte de testes.
 *
 * Executado pelo WP-CLI lendo da entrada padrão:
 *   docker compose exec -T -u www-data wordpress wp eval-file - preparar   < ambiente.php
 *   docker compose exec -T -u www-data wordpress wp eval-file - desmontar  < ambiente.php
 *
 * Só mexe no que é da suíte: usuários com prefixo festa_t_ / festa-t-,
 * inscrições cujo responsável começa com "Teste Auto" e o post privado de
 * teste. As configurações do plugin são guardadas antes e restauradas depois.
 */

use Festa\Audit\AuditLog;
use Festa\Cpt\CptInscricao;
use Festa\Pages\Pages;
use Festa\Portal\Portal;
use Festa\Portal\Store;
use Festa\Settings\Settings;

require_once ABSPATH . 'wp-admin/includes/user.php';

const FT_WP_PREFIX     = 'festa_t_';
const FT_PORTAL_PREFIX = 'festa-t-';
const FT_NOME_PREFIX   = 'Teste Auto';
const FT_BACKUP        = 'festa_tests_backup';
const FT_PRIVATE_TITLE = 'Festa teste: post privado';

function ft_limpar_entidades(): void
{
    global $wpdb;

    // Inscrições criadas pela suíte (arquivos em disco saem junto).
    $ids = get_posts([
        'post_type'      => CptInscricao::POST_TYPE,
        'post_status'    => 'any',
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'meta_query'     => [['key' => '_nome_responsavel', 'value' => FT_NOME_PREFIX, 'compare' => 'LIKE']],
    ]);
    foreach ($ids as $id) {
        CptInscricao::delete((int) $id);
        $wpdb->delete($wpdb->prefix . 'festa_audit_log', ['inscricao_id' => (int) $id]);
    }

    // Inscrições criadas por fora do fluxo (ex.: XML-RPC) com título de teste.
    $extras = $wpdb->get_col($wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_title LIKE %s",
        CptInscricao::POST_TYPE,
        $wpdb->esc_like(FT_NOME_PREFIX) . '%'
    ));
    foreach ($extras as $id) {
        CptInscricao::delete((int) $id);
    }

    foreach (get_users(['search' => FT_WP_PREFIX . '*', 'search_columns' => ['user_login']]) as $u) {
        wp_delete_user($u->ID);
    }

    foreach ((array) $wpdb->get_col($wpdb->prepare(
        'SELECT id FROM ' . $wpdb->prefix . 'festa_portal_users WHERE login LIKE %s',
        $wpdb->esc_like(FT_PORTAL_PREFIX) . '%'
    )) as $pid) {
        Store::delete_user((int) $pid);
    }
    $wpdb->query($wpdb->prepare(
        'DELETE FROM ' . $wpdb->prefix . 'festa_portal_log WHERE login LIKE %s OR detalhe LIKE %s',
        $wpdb->esc_like(FT_PORTAL_PREFIX) . '%',
        '%' . $wpdb->esc_like(FT_WP_PREFIX) . '%'
    ));

    // Consulta direta: no WP-CLI não há usuário logado, e o WP_Query esconde
    // posts privados de quem não pode lê-los.
    foreach ((array) $wpdb->get_col($wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'post' AND post_title = %s",
        FT_PRIVATE_TITLE
    )) as $pid) {
        wp_delete_post((int) $pid, true);
    }

    // Contadores de tentativas e travas.
    $wpdb->query(
        "DELETE FROM {$wpdb->options} WHERE option_name REGEXP '^_transient_(timeout_)?festa_(rl|fail|pf|flash|portal_flash|portal_err|defesa_ids)_' OR option_name LIKE 'festa\\_lock\\_%'"
    );
    $wpdb->query('DELETE FROM ' . $wpdb->prefix . 'festa_counters');
}

function ft_preparar(): array
{
    // Guarda as configurações atuais só na primeira vez (uma execução
    // interrompida não pode sobrescrever o backup original).
    if (get_option(FT_BACKUP) === false) {
        add_option(FT_BACKUP, [
            'settings' => get_option(Settings::OPTION, null),
            'portal'   => get_option(Portal::OPTION, null),
        ], '', false);
    }

    ft_limpar_entidades();

    $settings                 = (array) get_option(Settings::OPTION, []);
    $settings['aberto']       = 1;
    $settings['abre_em']      = '';
    $settings['encerra_em']   = '';
    $settings['limite_vagas'] = 0; // sem limite: os testes de vagas definem o seu e voltam a 0
    update_option(Settings::OPTION, $settings);

    $portal = Portal::settings();
    update_option(Portal::OPTION, ['enabled' => 1, 'slug' => $portal['slug']]);

    $wp = [];
    foreach ([
        'admin'     => 'administrator',
        'comissao'  => 'festa_comissao',
        'defesa'    => 'festa_defesacivil',
        'assinante' => 'subscriber',
    ] as $papel => $role) {
        $login = FT_WP_PREFIX . $papel;
        $senha = wp_generate_password(24, false);
        $id    = wp_insert_user([
            'user_login' => $login,
            'user_pass'  => $senha,
            'user_email' => $login . '@example.test',
            'role'       => $role,
        ]);
        if (is_wp_error($id)) {
            throw new RuntimeException($id->get_error_message());
        }
        $wp[$papel] = ['id' => $id, 'login' => $login, 'senha' => $senha];
    }

    $portal_users = [];
    foreach ([
        'comissao' => ['ver', 'analisar', 'tenda', 'recibo', 'editar', 'exportar'],
        'defesa'   => ['colchoes'],
        'leitor'   => ['ver'],
        'novo'     => ['ver'],
        'painel'   => ['dashboard'], // só o Dashboard: nenhuma tela com dados pessoais
    ] as $papel => $perms) {
        $login  = FT_PORTAL_PREFIX . $papel;
        $result = Store::create_user('Teste Portal ' . ucfirst($papel), $login, $login . '@example.test', $perms);
        if (is_wp_error($result)) {
            throw new RuntimeException($result->get_error_message());
        }
        $senha = $result['senha'];
        if ($papel !== 'novo') {
            // Contas já ativadas: senha conhecida, sem troca obrigatória.
            $senha = 'Tst-' . wp_generate_password(20, false) . '#9x';
            Store::set_password($result['id'], $senha, false);
        }
        $portal_users[$papel] = ['id' => $result['id'], 'login' => $login, 'senha' => $senha, 'perms' => $perms];
    }

    $private_id = wp_insert_post([
        'post_title'   => FT_PRIVATE_TITLE,
        'post_content' => 'conteudo-privado-festa-teste',
        'post_status'  => 'private',
        'post_author'  => $wp['admin']['id'],
    ]);

    global $wpdb;

    return [
        'prefix'     => $wpdb->prefix,
        'wp'         => $wp,
        'portal'     => $portal_users,
        'portal_url' => Portal::url(),
        'pages'      => [
            'pre'       => Pages::url('pre'),
            'inscricao' => Pages::url('inscricao'),
            'painel'    => Pages::url('painel'),
        ],
        'private_post' => $private_id,
        'uploads_dir'  => FESTA_UPLOADS_DIR,
        'abspath'      => ABSPATH,
    ];
}

function ft_desmontar(): array
{
    ft_limpar_entidades();

    $backup = get_option(FT_BACKUP);
    if (is_array($backup)) {
        foreach (['settings' => Settings::OPTION, 'portal' => Portal::OPTION] as $k => $option) {
            if ($backup[$k] === null) {
                delete_option($option);
            } else {
                update_option($option, $backup[$k]);
            }
        }
        delete_option(FT_BACKUP);
    }

    return ['ok' => true];
}

$modo = $args[0] ?? '';
$out  = match ($modo) {
    'preparar'  => ft_preparar(),
    'desmontar' => ft_desmontar(),
    default     => throw new InvalidArgumentException('Use: preparar | desmontar'),
};

echo "\n@@FESTA_JSON@@" . wp_json_encode($out) . "\n";
