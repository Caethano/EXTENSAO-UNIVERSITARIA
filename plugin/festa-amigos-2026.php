<?php
/**
 * Plugin Name:  Festa dos Amigos 2026
 * Description:  Gestão de Inscrições e Documentos Públicos para o evento Festa dos Amigos 2026: pré-cadastro, finalização, análise, sorteio de tenda, comprovantes e Termo de Cessão, com painel de gerenciamento e portal externo com login próprio. Shortcodes: [ini_inscricao], [festa_inscricao], [festa_painel].
 * Version:      2.0.0
 * Requires at least: 6.4
 * Requires PHP: 8.2
 * Author:       Comissão Festa dos Amigos
 * License:      GPL-2.0-or-later
 * Text Domain:  festa-amigos-2026
 * Domain Path:  /languages
 */

declare(strict_types=1);

// Bloco de segurança primário: nunca executar fora do contexto do WordPress.
defined('ABSPATH') || exit;

// ---------------------------------------------------------------------------
// Verificação de versão do PHP (antes de qualquer sintaxe PHP 8.2+)
// Retorna erro amigável em vez de fatal branco caso o host seja antigo.
// ---------------------------------------------------------------------------
if (version_compare(PHP_VERSION, '8.2.0', '<')) {
    add_action('admin_notices', static function (): void {
        printf(
            '<div class="notice notice-error"><p>'
            . '<strong>[Festa dos Amigos 2026]</strong> '
            . 'Requer PHP 8.2 ou superior. Versão atual: <code>%s</code>. '
            . 'Contate o seu provedor de hospedagem para atualizar o PHP.</p></div>',
            esc_html(PHP_VERSION)
        );
    });
    return; // Aborta o carregamento do plugin de forma controlada.
}

// ---------------------------------------------------------------------------
// Constantes de caminho
// ---------------------------------------------------------------------------
define('FESTA_VERSION',     '2.0.0');
define('FESTA_PLUGIN_FILE', __FILE__);
define('FESTA_PLUGIN_DIR',  plugin_dir_path(__FILE__));
define('FESTA_PLUGIN_URL',  plugin_dir_url(__FILE__));

/**
 * Diretório de uploads privados posicionado FORA de wp-content/uploads.
 * Inacessível via HTTP direto; protegido por .htaccess e servido via PHP.
 * Caminho final: a pasta festa-uploads-privados/ ao lado da raiz do WordPress.
 *
 * O caminho é montado com dirname() e não com "/../": o wp_mkdir_p() recusa
 * qualquer caminho que contenha "../", e o diretório nunca chegava a ser criado.
 *
 * Em hospedagens que não deixam gravar acima da raiz do site, cai em
 * wp-content/uploads/festa-uploads-privados/ (com as mesmas proteções).
 * Para escolher outro lugar, defina FESTA_UPLOADS_DIR no wp-config.php.
 */
if (! defined('FESTA_UPLOADS_DIR')) {
    $festa_parent_dir  = dirname(rtrim(ABSPATH, '/\\'));
    $festa_uploads_dir = $festa_parent_dir . '/festa-uploads-privados';

    if (! is_dir($festa_uploads_dir) && ! is_writable($festa_parent_dir)) {
        $festa_uploads_dir = rtrim(WP_CONTENT_DIR, '/\\') . '/uploads/festa-uploads-privados';
    }

    define('FESTA_UPLOADS_DIR', $festa_uploads_dir);
    unset($festa_parent_dir, $festa_uploads_dir);
}

// ---------------------------------------------------------------------------
// Autoloader PSR-4 (Festa\ → includes/)
// Deve ser carregado ANTES de qualquer referência a classes do namespace Festa\.
// ---------------------------------------------------------------------------
$autoloader_path = FESTA_PLUGIN_DIR . 'includes/class-autoloader.php';

if (! file_exists($autoloader_path)) {
    add_action('admin_notices', static function (): void {
        echo '<div class="notice notice-error"><p>'
            . '<strong>[Festa dos Amigos 2026]</strong> '
            . 'Arquivo <code>includes/class-autoloader.php</code> não encontrado. '
            . 'Reinstale o plugin.</p></div>';
    });
    return;
}

require_once $autoloader_path;

// ---------------------------------------------------------------------------
// Hooks de ciclo de vida
// ---------------------------------------------------------------------------
register_activation_hook(
    __FILE__,
    static function (): void {
        try {
            Festa\Plugin::activate();
        } catch (\Throwable $e) {
            // Em caso de erro na ativação, exibe mensagem e impede a ativação.
            // wp_die() com link de volta é o padrão do WordPress para isso.
            wp_die(
                sprintf(
                    '<p><strong>[Festa dos Amigos 2026]</strong> Erro durante a ativação:</p>'
                    . '<pre>%s</pre>'
                    . '<p><a href="%s">&laquo; Voltar para Plugins</a></p>',
                    esc_html($e->getMessage()),
                    esc_url(admin_url('plugins.php'))
                ),
                'Erro de Ativação',
                ['response' => 500, 'back_link' => false]
            );
        }
    }
);

register_deactivation_hook(
    __FILE__,
    static function (): void {
        try {
            Festa\Plugin::deactivate();
        } catch (\Throwable $e) {
            error_log('[Festa dos Amigos 2026] Erro na desativação: ' . $e->getMessage());
        }
    }
);

// ---------------------------------------------------------------------------
// Bootstrap — dispara em plugins_loaded para garantir que o WP está pronto.
// ---------------------------------------------------------------------------
add_action(
    'plugins_loaded',
    static function (): void {
        try {
            Festa\Plugin::boot();
        } catch (\Throwable $e) {
            error_log('[Festa dos Amigos 2026] Erro fatal no boot: ' . $e->getMessage());

            // Exibe admin_notice com o erro completo apenas quando WP_DEBUG está ativo.
            if (defined('WP_DEBUG') && WP_DEBUG) {
                add_action('admin_notices', static function () use ($e): void {
                    printf(
                        '<div class="notice notice-error"><p>'
                        . '<strong>[Festa dos Amigos 2026] Erro no boot:</strong> %s'
                        . '<br><small>%s:%d</small></p></div>',
                        esc_html($e->getMessage()),
                        esc_html($e->getFile()),
                        (int) $e->getLine()
                    );
                });
            }
        }
    }
);
