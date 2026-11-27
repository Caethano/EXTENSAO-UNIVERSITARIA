<?php

declare(strict_types=1);

namespace Festa\Admin;

use Festa\Audit\AuditLog;
use Festa\Security\LgpdAnonymizer;
use Festa\Settings\Settings;

/**
 * Gerencia o menu do wp-admin.
 *
 * - "Festa dos Amigos": painel de inscrições da Comissão, com dashboard,
 *   auditoria, configurações, portal externo e ferramentas LGPD.
 * - "Busca de Colchões": página exclusiva da Defesa Civil, que não enxerga
 *   o painel (a restrição total de menu é aplicada por
 *   Roles::restrict_defesacivil_menu() no Módulo 1).
 */
final class AdminMenu
{
    public const SLUG           = 'festa-inscricoes';
    public const SLUG_SETTINGS  = 'festa-configuracoes';
    public const SLUG_DASHBOARD = 'festa-dashboard';

    // Slug canônico — deve coincidir com o permitido em Roles::restrict_defesacivil_menu()
    public const DEFESA_PAGE_SLUG = 'festa_busca_colchoes';

    public static function boot_hooks(): void
    {
        // Prioridade 10: registra páginas antes das restrições do Módulo 1 (prioridade 999)
        add_action('admin_menu', [self::class, 'register'], 10);
    }

    public static function register(): void
    {
        $ver   = CtxAdmin::CAPS['ver'];
        $count = current_user_can($ver) ? Panel::count_pending() : 0;
        $badge = $count
            ? ' <span class="awaiting-mod count-' . $count . '"><span class="pending-count">' . $count . '</span></span>'
            : '';

        add_menu_page(
            'Festa dos Amigos 2026 — Inscrições',
            'Festa dos Amigos' . $badge,
            $ver,
            self::SLUG,
            [Panel::class, 'page'],
            'dashicons-groups',
            30
        );
        add_submenu_page(self::SLUG, 'Inscrições', 'Inscrições', $ver, self::SLUG, [Panel::class, 'page']);
        add_submenu_page(
            self::SLUG,
            'Dashboard — Festa dos Amigos 2026',
            'Dashboard',
            CtxAdmin::CAPS['dashboard'],
            self::SLUG_DASHBOARD,
            [Dashboard::class, 'render']
        );
        add_submenu_page(
            self::SLUG,
            'Logs de Auditoria — Festa dos Amigos 2026',
            'Logs de Auditoria',
            $ver, // Capability exclusiva da Comissão
            AuditLog::PAGE_SLUG,
            [AuditLog::class, 'render_admin_page']
        );
        add_submenu_page(
            self::SLUG,
            'Configurações — Festa dos Amigos 2026',
            'Configurações',
            CtxAdmin::CAPS['config'],
            self::SLUG_SETTINGS,
            [Settings::class, 'render']
        );
        add_submenu_page(
            self::SLUG,
            'Portal externo — Festa dos Amigos 2026',
            'Portal externo',
            PortalAdmin::CAP,
            PortalAdmin::SLUG,
            [PortalAdmin::class, 'render']
        );
        add_submenu_page(
            self::SLUG,
            'Ferramentas LGPD — Festa dos Amigos 2026',
            'Ferramentas LGPD',
            'manage_options', // Restrito a administradores/DPO
            LgpdAnonymizer::PAGE_SLUG,
            [LgpdAnonymizer::class, 'render_page']
        );

        // Página exclusiva da Defesa Civil
        add_menu_page(
            'Busca de Colchões — Festa dos Amigos 2026', // page title
            'Busca de Colchões',                          // menu title
            CtxAdmin::CAPS['colchoes'],                   // capability
            self::DEFESA_PAGE_SLUG,
            [AdminDefesa::class, 'render_page'],
            'dashicons-search',
            31
        );
    }
}
