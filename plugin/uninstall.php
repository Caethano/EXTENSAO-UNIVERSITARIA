<?php

declare(strict_types=1);

// Garante execução exclusivamente via mecanismo oficial do WP
defined('WP_UNINSTALL_PLUGIN') || exit;

// ---------------------------------------------------------------------------
// 1. Remover roles customizadas e as capabilities entregues ao administrador
// ---------------------------------------------------------------------------
$roles_to_remove = ['festa_participante', 'festa_comissao', 'festa_defesacivil'];

foreach ($roles_to_remove as $role) {
    remove_role($role);
}

$admin = get_role('administrator');
if ($admin) {
    foreach (array_keys($admin->capabilities) as $cap) {
        if (str_starts_with((string) $cap, 'festa_')) {
            $admin->remove_cap((string) $cap);
        }
    }
}

// ---------------------------------------------------------------------------
// 2. Remover opções do plugin
//
// As chaves criptográficas (arquivo festa-chaves.php na pasta privada, ou
// as constantes FESTA_ENC_KEY / FESTA_HMAC_KEY no wp-config.php) NÃO são
// removidas: as inscrições continuam no banco, e sem as chaves os CPFs
// ficariam ilegíveis e a busca por CPF deixaria de funcionar numa
// reinstalação. Removê-las é uma decisão do DPO, feita à parte.
// ---------------------------------------------------------------------------
$options = [
    'festa_version',
    'festa_db_version',
    'festa_settings',
    'festa_portal',
    'festa_pages_version',
    'festa_page_pre',
    'festa_page_inscricao',
    'festa_page_painel',
];

foreach ($options as $option) {
    delete_option($option);
}

// O limite de vagas e a abertura das inscrições ficam dentro de festa_settings.
// Travas (ex.: a das vagas) e avisos temporários também são do plugin.
global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query(
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'festa\\_lock\\_%' "
    . "OR option_name REGEXP '^_transient_(timeout_)?festa_'"
);

// ---------------------------------------------------------------------------
// 3. Remover tabelas: audit log, portal externo (contas, sessões, registro)
//    e contadores de tentativas
// ---------------------------------------------------------------------------

foreach (['festa_audit_log', 'festa_portal_users', 'festa_portal_sessions', 'festa_portal_log', 'festa_counters'] as $table) {
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}{$table}");
}

// ---------------------------------------------------------------------------
// NOTA DPO (LGPD):
// As inscrições (CPT inscricao_festa) com todos os seus metadados — inclusive
// os da lista de espera (_fila_em) e do indeferimento (_indeferimento_motivo,
// _indeferido_em, _reabertura_motivo, _reaberto_em) —, as páginas criadas
// pelo plugin e os arquivos em festa-uploads-privados/ NÃO são removidos
// automaticamente.
// A exclusão de documentos com dados pessoais deve ser autorizada pelo
// Encarregado de Dados (DPO) após cumprimento do período de retenção legal.
// Remoção manual: apagar a pasta festa-uploads-privados/, que fica ao lado
// da pasta do WordPress (ou em wp-content/uploads/, se a hospedagem não
// permitir gravar acima da raiz do site).
// ---------------------------------------------------------------------------
