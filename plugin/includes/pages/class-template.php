<?php

declare(strict_types=1);

namespace Festa\Pages;

/**
 * Registra o modelo "Festa dos Amigos — Página inteira" para páginas, servido
 * a partir do próprio plugin (funciona com qualquer tema).
 */
final class Template
{
    public const SLUG = 'festa-fullpage.php';

    public static function boot_hooks(): void
    {
        add_filter('theme_page_templates', [self::class, 'register']);
        add_filter('template_include', [self::class, 'load']);
    }

    /**
     * Exibe o modelo na lista "Modelo" do editor de páginas.
     *
     * @param array<string, string> $templates
     * @return array<string, string>
     */
    public static function register(array $templates): array
    {
        $templates[self::SLUG] = 'Festa dos Amigos — Página inteira';

        return $templates;
    }

    /** Usa o arquivo do plugin quando a página estiver com o modelo marcado. */
    public static function load(string $template): string
    {
        if (! is_singular('page') || get_page_template_slug(get_queried_object_id()) !== self::SLUG) {
            return $template;
        }

        $file = FESTA_PLUGIN_DIR . 'templates/' . self::SLUG;
        if (! file_exists($file)) {
            return $template;
        }

        // Garante CSS/JS dos formulários já no <head> da página inteira.
        add_action('wp_enqueue_scripts', static function (): void {
            wp_enqueue_style('festa-forms');
            wp_enqueue_script('festa-forms');
        }, 20);

        return $file;
    }
}
