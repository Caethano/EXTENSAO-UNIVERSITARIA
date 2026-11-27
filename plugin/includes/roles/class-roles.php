<?php

declare(strict_types=1);

namespace Festa\Roles;

/**
 * Gerencia o registro, remoção e restrições comportamentais das três roles
 * customizadas do plugin.
 *
 * Roles:
 *  - festa_participante  : front-end only (shortcodes). Bloqueado no wp-admin.
 *  - festa_comissao      : wp-admin. Analisa inscrições pelo painel de gerenciamento.
 *  - festa_defesacivil   : wp-admin restrito a uma única página de busca por CPF.
 */
final class Roles
{
    /**
     * Mapa canônico de roles → capabilities.
     *
     * Só capabilities do próprio plugin (festa_*): o painel confere apenas
     * estas. Nenhum papel recebe as capabilities nativas do CPT
     * (edit_inscricao_festas etc.) nem as de posts do site (edit_posts,
     * read_private_posts) — com elas a Comissão lia posts privados de todo o
     * site, escrevia posts e criava inscrições falsas pelo XML-RPC.
     */
    private const ROLES = [

        'festa_participante' => [
            'display_name' => 'Festa — Participante',
            'caps'         => [
                'read'                     => true,
                'festa_inscrever'          => true,
                'festa_upload_docs'        => true,
                'festa_ver_proprio_status' => true,
            ],
        ],

        'festa_comissao' => [
            'display_name' => 'Festa — Comissão',
            'caps'         => [
                'read'                           => true,
                // Capabilities funcionais do plugin
                'festa_analisar_inscricao'       => true,
                'festa_upload_deferimento'       => true,
                'festa_inserir_numero_tenda'     => true,
                'festa_aprovar_recibo_tenda'     => true,
                'festa_ver_relatorio_conclusao'  => true,
                'festa_ver_dashboard'            => true,
            ],
        ],

        'festa_defesacivil' => [
            'display_name' => 'Festa — Defesa Civil',
            'caps'         => [
                'read'                     => true,
                'festa_buscar_cpf_nome'    => true,
                'festa_confirmar_colchoes' => true,
            ],
        ],
    ];

    /**
     * Capabilities do plugin entregues ao administrador do site, que passa a
     * enxergar e operar todas as telas. Excluir inscrições e alterar as
     * configurações são exclusivas dele — a Comissão não exclui.
     */
    private const ADMIN_CAPS = [
        'festa_analisar_inscricao',
        'festa_upload_deferimento',
        'festa_inserir_numero_tenda',
        'festa_aprovar_recibo_tenda',
        'festa_ver_relatorio_conclusao',
        'festa_ver_dashboard',
        'festa_buscar_cpf_nome',
        'festa_confirmar_colchoes',
        'festa_excluir_inscricao',
        'festa_configurar',
    ];

    // -----------------------------------------------------------------------
    // Registro / Remoção (ciclo de vida do plugin)
    // -----------------------------------------------------------------------

    /**
     * Registra as três roles e entrega as capabilities ao administrador.
     * Chamado em register_activation_hook e nas atualizações do plugin.
     * O remove_role() antes do add_role() evita inconsistências em re-ativações.
     */
    public static function register(): void
    {
        foreach (self::ROLES as $role_key => $role_data) {
            remove_role($role_key);
            add_role($role_key, $role_data['display_name'], $role_data['caps']);
        }

        $admin = get_role('administrator');
        if ($admin) {
            foreach (self::ADMIN_CAPS as $cap) {
                $admin->add_cap($cap);
            }
        }
    }

    /**
     * Remove as três roles. Chamado em register_deactivation_hook e uninstall.php.
     */
    public static function remove(): void
    {
        foreach (array_keys(self::ROLES) as $role_key) {
            remove_role($role_key);
        }

        $admin = get_role('administrator');
        if ($admin) {
            foreach (self::ADMIN_CAPS as $cap) {
                $admin->remove_cap($cap);
            }
        }
    }

    // -----------------------------------------------------------------------
    // Hooks de comportamento (boot em plugins_loaded)
    // -----------------------------------------------------------------------

    public static function boot_hooks(): void
    {
        // Bloqueia festa_participante no wp-admin (redirect para home)
        add_action('init', [self::class, 'redirect_participant_from_admin']);

        // Oculta admin bar para festa_participante
        add_filter('show_admin_bar', [self::class, 'hide_admin_bar_for_participant']);

        // Restringe menu do wp-admin para festa_defesacivil
        add_action('admin_menu', [self::class, 'restrict_defesacivil_menu'], 999);
    }

    // -----------------------------------------------------------------------
    // Handlers dos hooks
    // -----------------------------------------------------------------------

    /**
     * Redireciona festa_participante para a home se tentar acessar /wp-admin.
     * Respeita requisições AJAX e REST para não quebrar operações legítimas.
     */
    public static function redirect_participant_from_admin(): void
    {
        if (
            ! is_admin()
            || wp_doing_ajax()
            || (defined('REST_REQUEST') && REST_REQUEST)
            || ! is_user_logged_in()
        ) {
            return;
        }

        // Detecta participante: tem festa_inscrever mas NÃO tem edit_posts
        if (
            current_user_can('festa_inscrever')
            && ! current_user_can('edit_posts')
        ) {
            wp_safe_redirect(home_url('/'));
            exit;
        }
    }

    /**
     * Oculta a admin bar para festa_participante no front-end.
     */
    public static function hide_admin_bar_for_participant(bool $show): bool
    {
        if (
            is_user_logged_in()
            && current_user_can('festa_inscrever')
            && ! current_user_can('edit_posts')
        ) {
            return false;
        }

        return $show;
    }

    /**
     * Remove todos os menus do wp-admin exceto a página exclusiva da Defesa Civil.
     * Executado apenas quando o usuário logado é festa_defesacivil.
     */
    public static function restrict_defesacivil_menu(): void
    {
        // Só age para usuários com a capability exclusiva da Defesa Civil
        // que NÃO sejam da Comissão (que tem edit_posts)
        if (
            ! current_user_can('festa_buscar_cpf_nome')
            || current_user_can('edit_posts')
        ) {
            return;
        }

        global $menu, $submenu;

        // Páginas permitidas: a busca da Defesa Civil + perfil próprio + dashboard
        $allowed = ['festa_busca_colchoes', 'profile.php', 'index.php'];

        foreach ($menu as $key => $item) {
            if (! in_array($item[2], $allowed, true)) {
                unset($menu[$key]);
            }
        }

        // Remove submenus órfãos
        foreach (array_keys($submenu) as $parent_slug) {
            if (! in_array($parent_slug, $allowed, true)) {
                unset($submenu[$parent_slug]);
            }
        }
    }
}
