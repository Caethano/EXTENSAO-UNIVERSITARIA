<?php

declare(strict_types=1);

namespace Festa\Admin;

use Festa\Audit\AuditLog;

/**
 * Contexto do wp-admin: usuário do WordPress, admin-post.php e nonces.
 */
final class CtxAdmin implements CtxDriver
{
    /**
     * Permissão das telas → capability das roles (ver Roles\Roles).
     * A Comissão e a Defesa Civil continuam com exatamente as capabilities
     * que já tinham; excluir e configurar ficam com o administrador.
     */
    public const CAPS = [
        'ver'       => 'festa_analisar_inscricao',
        'dashboard' => 'festa_ver_dashboard',
        'analisar'  => 'festa_upload_deferimento',
        'tenda'    => 'festa_inserir_numero_tenda',
        'recibo'   => 'festa_aprovar_recibo_tenda',
        'colchoes' => 'festa_confirmar_colchoes',
        'editar'   => 'festa_analisar_inscricao',
        'exportar' => 'festa_ver_relatorio_conclusao',
        'excluir'  => 'festa_excluir_inscricao',
        'config'   => 'festa_configurar',
    ];

    public function is_portal(): bool
    {
        return false;
    }

    public function can(string $perm): bool
    {
        return isset(self::CAPS[$perm]) && current_user_can(self::CAPS[$perm]);
    }

    public function url(string $screen, array $args): string
    {
        $slugs = [
            'inscricoes' => AdminMenu::SLUG,
            'dashboard'  => AdminMenu::SLUG_DASHBOARD,
            'colchoes'   => AdminMenu::DEFESA_PAGE_SLUG,
            'auditoria'  => AuditLog::PAGE_SLUG,
            'config'     => AdminMenu::SLUG_SETTINGS,
        ];

        return add_query_arg(
            urlencode_deep($args),
            admin_url('admin.php?page=' . ($slugs[$screen] ?? AdminMenu::SLUG))
        );
    }

    public function download_url(string $action, array $args, string $nonce): string
    {
        return wp_nonce_url(
            add_query_arg(urlencode_deep($args), admin_url('admin-post.php?action=' . $action)),
            $nonce
        );
    }

    public function form_action(): string
    {
        return admin_url('admin-post.php');
    }

    public function form_fields(string $action, string $nonce): string
    {
        return '<input type="hidden" name="action" value="' . esc_attr($action) . '">'
            . '<input type="hidden" name="_wpnonce" value="' . esc_attr(wp_create_nonce($nonce)) . '">';
    }

    public function filter_action(): string
    {
        return admin_url('admin.php');
    }

    public function filter_fields(string $screen): string
    {
        parse_str((string) wp_parse_url($this->url($screen, []), PHP_URL_QUERY), $query);

        return '<input type="hidden" name="page" value="' . esc_attr((string) ($query['page'] ?? AdminMenu::SLUG)) . '">';
    }

    public function guard(string $perm, string $nonce): void
    {
        if (! $this->can($perm)) {
            wp_die('Sem permissão.', 'Sem permissão', ['response' => 403]);
        }
        check_admin_referer($nonce);
    }

    public function stop(string $message, int $code): void
    {
        wp_die(esc_html($message), '', ['response' => $code]);
    }

    public function redirect(string $url): void
    {
        wp_safe_redirect($url);
    }

    public function actor(): string
    {
        return '';
    }

    public function user_key(): string
    {
        return 'wp' . get_current_user_id();
    }
}
