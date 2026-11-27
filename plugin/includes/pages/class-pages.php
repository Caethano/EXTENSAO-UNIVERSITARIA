<?php

declare(strict_types=1);

namespace Festa\Pages;

/**
 * Criação automática das três páginas públicas do participante e resolução
 * dos seus endereços (usados nos shortcodes e nos e-mails).
 */
final class Pages
{
    private const VERSION_OPTION = 'festa_pages_version';

    /**
     * @return array<string, array{option: string, title: string, slug: string, shortcode: string}>
     */
    private static function pages(): array
    {
        return [
            'pre' => [
                'option'    => 'festa_page_pre',
                'title'     => 'Pré-Cadastro — Festa dos Amigos 2026',
                'slug'      => 'pre-inscricao',
                'shortcode' => '[ini_inscricao]',
            ],
            'inscricao' => [
                'option'    => 'festa_page_inscricao',
                'title'     => 'Inscrição — Festa dos Amigos 2026',
                'slug'      => 'inscricao',
                'shortcode' => '[festa_inscricao]',
            ],
            'painel' => [
                'option'    => 'festa_page_painel',
                'title'     => 'Painel de Inscrição — Festa dos Amigos 2026',
                'slug'      => 'painel-inscricao',
                'shortcode' => '[festa_painel]',
            ],
        ];
    }

    public static function boot_hooks(): void
    {
        // Garante as páginas também em atualizações sem reativação do plugin.
        add_action('admin_init', [self::class, 'maybe_ensure']);
    }

    public static function maybe_ensure(): void
    {
        if (get_option(self::VERSION_OPTION) !== FESTA_VERSION) {
            self::ensure_pages();
        }
    }

    /**
     * Garante que cada shortcode tenha a sua página publicada.
     * Executa na ativação; recria a página se ela tiver sido excluída.
     * Páginas que o site já tinha (criadas à mão com o shortcode) são
     * reaproveitadas sem nenhuma alteração.
     */
    public static function ensure_pages(): void
    {
        foreach (self::pages() as $page) {
            $page_id = (int) get_option($page['option']);
            $post    = $page_id ? get_post($page_id) : null;

            if ($post && $post->post_status !== 'trash') {
                continue;
            }

            // Restaura da lixeira, se for o caso.
            if ($post && $post->post_status === 'trash') {
                wp_untrash_post($page_id);
                wp_publish_post($page_id);
                continue;
            }

            // Reaproveita a página criada manualmente com o shortcode.
            $existing = get_page_by_path($page['slug']);
            if (
                $existing
                && $existing->post_type === 'page'
                && $existing->post_status === 'publish'
                && str_contains((string) $existing->post_content, $page['shortcode'])
            ) {
                update_option($page['option'], (int) $existing->ID);
                continue;
            }

            $new_id = wp_insert_post([
                'post_title'   => $page['title'],
                'post_name'    => $page['slug'],
                'post_content' => $page['shortcode'],
                'post_status'  => 'publish',
                'post_type'    => 'page',
                'post_author'  => get_current_user_id() ?: 1,
            ]);

            if ($new_id && ! is_wp_error($new_id)) {
                update_option($page['option'], (int) $new_id);
                // Só as páginas criadas pelo plugin usam o modelo de página inteira.
                update_post_meta((int) $new_id, '_wp_page_template', Template::SLUG);
            }
        }

        update_option(self::VERSION_OPTION, FESTA_VERSION);
    }

    /**
     * URL pública de uma das páginas: pre | inscricao | painel.
     * Sem página registrada, usa o endereço padrão do slug.
     */
    public static function url(string $which): string
    {
        $pages = self::pages();
        if (! isset($pages[$which])) {
            return home_url('/');
        }

        $page_id = (int) get_option($pages[$which]['option']);
        if ($page_id && get_post_status($page_id) === 'publish') {
            return (string) get_permalink($page_id);
        }

        return home_url('/' . $pages[$which]['slug'] . '/');
    }

    /** ID da página, para o link "Editar página". */
    public static function id(string $which): int
    {
        $pages = self::pages();

        return isset($pages[$which]) ? (int) get_option($pages[$which]['option']) : 0;
    }
}
