<?php
/**
 * Apoio do tests/popular_banco.py, executado pelo WP-CLI lendo da entrada padrão:
 *   docker compose exec -T -u www-data wordpress wp eval-file - info    < povoamento.php
 *   docker compose exec -T -u www-data wordpress wp eval-file - limpar  < povoamento.php
 *
 * info:    endereços das páginas, pasta privada e quantas inscrições existem.
 * limpar:  apaga TODAS as inscrições (com os arquivos e o registro de auditoria
 *          delas), sessões e limites de tentativa; cria ou renova as contas de
 *          equipe de teste (comissao.teste e defesa.teste) com senhas novas;
 *          volta o limite de vagas para 0 (sem limite).
 * esgotar: ajusta o limite de vagas para as vagas já ocupadas (as próximas
 *          finalizações entram na lista de espera).
 */

use Festa\Cpt\CptInscricao;
use Festa\Pages\Pages;
use Festa\Settings\Settings;
use Festa\Status\Vagas;

global $wpdb;

$fp_modo = $args[0] ?? '';

function fp_ids(): array
{
    global $wpdb;
    return array_map('intval', $wpdb->get_col($wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s ORDER BY ID",
        CptInscricao::POST_TYPE
    )));
}

function fp_saida(array $dados): void
{
    echo "\n@@FESTA_JSON@@" . wp_json_encode($dados) . "\n";
}

if ($fp_modo === 'info') {
    fp_saida([
        'prefix'      => $wpdb->prefix,
        'uploads_dir' => FESTA_UPLOADS_DIR,
        'inscricoes'  => fp_ids(),
        'pages'       => [
            'pre'       => Pages::url('pre'),
            'inscricao' => Pages::url('inscricao'),
            'painel'    => Pages::url('painel'),
        ],
    ]);
    return;
}

if ($fp_modo === 'esgotar') {
    $fp_settings                 = (array) get_option(Settings::OPTION, []);
    $fp_settings['limite_vagas'] = max(1, Vagas::ocupadas());
    update_option(Settings::OPTION, $fp_settings);
    fp_saida(['limite' => $fp_settings['limite_vagas'], 'restantes' => Vagas::restantes()]);
    return;
}

if ($fp_modo !== 'limpar') {
    WP_CLI::error('modo inválido: use info, limpar ou esgotar');
}

$fp_ids = fp_ids();
foreach ($fp_ids as $fp_id) {
    CptInscricao::delete($fp_id);
}
if ($fp_ids) {
    $wpdb->query("DELETE FROM {$wpdb->prefix}festa_audit_log WHERE inscricao_id IN (" . implode(',', $fp_ids) . ')');
}

// Começa sem limite de vagas: o modo "esgotar" o ajusta de novo no fim, para
// a lista de espera (sem isto, o limite da execução anterior mudaria o roteiro).
$fp_settings                 = (array) get_option(Settings::OPTION, []);
$fp_settings['limite_vagas'] = 0;
update_option(Settings::OPTION, $fp_settings);

// Sessões de participante, mensagens, travas e limites: tudo efêmero.
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name REGEXP '^_transient_(timeout_)?festa_'");
$wpdb->query("DELETE FROM {$wpdb->prefix}festa_counters");

$fp_sobras = array_values(array_filter(
    is_dir(FESTA_UPLOADS_DIR) ? scandir(FESTA_UPLOADS_DIR) : [],
    static fn ($f) => ctype_digit((string) $f)
));

$fp_equipe = [];
foreach ([
    'comissao.teste' => ['festa_comissao', 'Comissão (teste)'],
    'defesa.teste'   => ['festa_defesacivil', 'Defesa Civil (teste)'],
] as $fp_login => [$fp_papel, $fp_nome]) {
    $fp_senha = wp_generate_password(16, false);
    $fp_user  = get_user_by('login', $fp_login);
    if ($fp_user) {
        wp_set_password($fp_senha, $fp_user->ID);
        $fp_user->set_role($fp_papel);
        $fp_uid = $fp_user->ID;
    } else {
        $fp_uid = wp_insert_user([
            'user_login'   => $fp_login,
            'user_pass'    => $fp_senha,
            'user_email'   => $fp_login . '@example.test',
            'display_name' => $fp_nome,
            'role'         => $fp_papel,
        ]);
        if (is_wp_error($fp_uid)) {
            WP_CLI::error($fp_uid->get_error_message());
        }
    }
    $fp_equipe[$fp_login] = ['id' => (int) $fp_uid, 'senha' => $fp_senha, 'papel' => $fp_papel];
}

fp_saida(['removidas' => $fp_ids, 'sobras_em_disco' => $fp_sobras, 'equipe' => $fp_equipe]);
