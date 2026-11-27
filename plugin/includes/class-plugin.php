<?php

declare(strict_types=1);

namespace Festa;

use Festa\Roles\Roles;
use Festa\Cpt;
use Festa\Crypto;
use Festa\Upload;
use Festa\Pdf;
use Festa\Frontend;
use Festa\Admin;
use Festa\Notifications;
use Festa\Audit;
use Festa\Security;
use Festa\Settings;
use Festa\Pages;
use Festa\Portal;

/**
 * Orquestrador central do plugin.
 * Chamado pelo arquivo raiz via hooks de ciclo de vida e plugins_loaded.
 */
final class Plugin
{
    /** Opção que guarda a versão instalada (dispara a atualização sem reativação). */
    private const VERSION_OPTION = 'festa_version';

    /**
     * Revisão do esquema (tabelas, roles e capabilities) dentro da mesma
     * versão do plugin. Mudou o esquema? Some 1: a instalação é refeita na
     * próxima visita, mesmo que FESTA_VERSION não mude.
     *  2 — capability festa_ver_dashboard; detalhe da auditoria com 1000 caracteres.
     */
    private const SCHEMA = 2;

    private const SCHEMA_OPTION = 'festa_db_version';

    private static bool $booted = false;

    // -----------------------------------------------------------------------
    // Ciclo de vida
    // -----------------------------------------------------------------------

    /** Executado em register_activation_hook. */
    public static function activate(): void
    {
        self::install();
        Pages\Pages::ensure_pages();
        flush_rewrite_rules();
    }

    /** Executado em register_deactivation_hook. */
    public static function deactivate(): void
    {
        Roles::remove();
        flush_rewrite_rules();
    }

    /**
     * Tudo o que precisa existir para o plugin funcionar: chaves, roles,
     * tabelas e o diretório protegido. Idempotente — roda na ativação e
     * sempre que os arquivos do plugin forem atualizados.
     */
    private static function install(): void
    {
        // Chaves criptográficas fora do banco: gera na primeira ativação ou
        // copia as que estiverem em wp_options, sem trocar os valores.
        // Chaves já existentes nunca são sobrescritas.
        Security\SecurityManager::enforce_htaccess();
        Crypto\Crypto::ensure_keys();

        Roles::register();
        Audit\AuditLog::create_table();
        Portal\Store::create_tables();
        Security\SecurityManager::create_table();

        update_option(self::VERSION_OPTION, FESTA_VERSION);
        update_option(self::SCHEMA_OPTION, self::SCHEMA);
    }

    /** Garante a atualização quando o plugin for trocado sem reativação. */
    public static function maybe_upgrade(): void
    {
        if (
            get_option(self::VERSION_OPTION) !== FESTA_VERSION
            || (int) get_option(self::SCHEMA_OPTION, 0) !== self::SCHEMA
        ) {
            self::install();
        }
    }

    // -----------------------------------------------------------------------
    // Bootstrap (plugins_loaded)
    // -----------------------------------------------------------------------

    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;

        load_plugin_textdomain(
            'festa-amigos-2026',
            false,
            dirname(plugin_basename(FESTA_PLUGIN_FILE)) . '/languages'
        );

        // Módulo 0 — Atualização sem reativação + páginas e modelo de página inteira
        add_action('init', [self::class, 'maybe_upgrade'], 5);
        Pages\Pages::boot_hooks();
        Pages\Template::boot_hooks();

        // Módulo 1 — Roles/redirects/restrições de menu
        Roles::boot_hooks();

        // Módulo 2 — CPT inscricao_festa
        Cpt\CptInscricao::boot_hooks();

        // Módulo 5 — Upload handler + controller de download seguro
        Upload\Handler::boot_hooks();

        // Módulo 6 — Geração de PDFs (listener: festa_status_transition)
        Pdf\Generator::boot_hooks();

        // Módulo 7 — Front-end: shortcodes + handlers do participante (autenticação silo)
        Frontend\Ui::boot_hooks();
        Frontend\AjaxHandler::boot_hooks();
        Frontend\ShortcodeIniInscricao::boot_hooks();
        Frontend\ShortcodeInscricao::boot_hooks();
        Frontend\ShortcodePainel::boot_hooks();

        // Módulo 8 — Gerenciamento: menu, painel, ações da comissão e Defesa Civil.
        // As ações são registradas sempre (admin-post.php); as telas, só no wp-admin.
        Admin\Panel::boot_hooks();
        Admin\AdminActions::boot_hooks();
        Admin\AdminDefesa::boot_hooks();
        Settings\Settings::boot_hooks();
        if (is_admin()) {
            Admin\AdminMenu::boot_hooks();
            Admin\PortalAdmin::boot_hooks();
        }

        // Módulo 9 — Notificações transacionais por e-mail (listener: festa_status_transition)
        Notifications\Email::boot_hooks();

        // Módulo 10 — Audit Log (listener: festa_status_transition)
        Audit\AuditLog::boot_hooks();

        // Módulo 12 — Portal externo de gerenciamento (fora do wp-admin)
        Portal\Portal::boot_hooks();
    }
}
