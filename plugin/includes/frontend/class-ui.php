<?php

declare(strict_types=1);

namespace Festa\Frontend;

use Festa\Crypto\Crypto;
use Festa\Settings\Settings;
use Festa\Status\StateMachine;
use Festa\Status\Vagas;
use Festa\Upload\Handler;

/**
 * Peças de interface compartilhadas pelos três shortcodes do participante:
 * CSS/JS, cabeçalho, cartões, campos e formulários.
 *
 * Todo o comportamento fica em assets/js/festa-forms.js, ligado por
 * atributos data-*; o HTML não carrega script nem estilo embutido.
 */
final class Ui
{
    private const SHORTCODES = ['ini_inscricao', 'festa_inscricao', 'festa_painel'];

    // -----------------------------------------------------------------------
    // CSS e JS
    // -----------------------------------------------------------------------

    public static function boot_hooks(): void
    {
        add_action('wp_enqueue_scripts', [self::class, 'register_assets']);
        add_action('template_redirect', [self::class, 'send_headers']);
    }

    /** A página atual tem um dos formulários do participante? */
    private static function is_plugin_page(): bool
    {
        $post = is_singular() ? get_post() : null;
        if (! $post) {
            return false;
        }
        foreach (self::SHORTCODES as $shortcode) {
            if (has_shortcode((string) $post->post_content, $shortcode)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Páginas do participante não podem ser carregadas dentro de outro site
     * (clickjacking: um site falso por cima do formulário de login).
     */
    public static function send_headers(): void
    {
        if (headers_sent() || ! self::is_plugin_page()) {
            return;
        }

        send_frame_options_header(); // X-Frame-Options: SAMEORIGIN
        header("Content-Security-Policy: frame-ancestors 'self'");
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: same-origin');
    }

    public static function register_assets(): void
    {
        wp_register_style('festa-forms', FESTA_PLUGIN_URL . 'assets/css/festa-forms.css', [], FESTA_VERSION);
        wp_register_script('festa-forms', FESTA_PLUGIN_URL . 'assets/js/festa-forms.js', [], FESTA_VERSION, true);
        wp_localize_script('festa-forms', 'festaForms', [
            // URL relativa à raiz do site: usa sempre o mesmo esquema/host
            // da página (evita bloqueio por conteúdo misto atrás de proxy).
            'ajaxUrl'      => self::admin_path('admin-ajax.php'),
            'nonceUrl'     => self::admin_path('admin-ajax.php') . '?action=festa_refresh_nonce',
            'nonce'        => wp_create_nonce('festa_auth_nonce'),
            'maxBytes'     => Handler::max_bytes(),
            'maxPostBytes' => Handler::max_post_bytes(),
            'maxLabel'     => Handler::human_size(Handler::max_bytes()),
        ]);

        // Página com um dos shortcodes: CSS já no <head>, sem piscar sem estilo.
        if (self::is_plugin_page()) {
            self::enqueue();
        }
    }

    public static function enqueue(): void
    {
        if (! wp_style_is('festa-forms', 'registered')) {
            self::register_assets();
        }
        wp_enqueue_style('festa-forms');
        wp_enqueue_script('festa-forms');
    }

    /** Caminho relativo de um arquivo do wp-admin (ex.: /wp-admin/admin-ajax.php). */
    private static function admin_path(string $file): string
    {
        $path = wp_parse_url(admin_url($file), PHP_URL_PATH);

        return $path ?: '/wp-admin/' . $file;
    }

    /** URL da página atual, usada para voltar após o envio sem JavaScript. */
    public static function current_url(): string
    {
        $id = get_queried_object_id();

        return $id ? (string) get_permalink($id) : home_url(add_query_arg([]));
    }

    // -----------------------------------------------------------------------
    // Estrutura da página
    // -----------------------------------------------------------------------

    /**
     * Abre o contêiner de uma página do participante, com o cabeçalho.
     * As telas com sessão aberta mostram dados pessoais: não vão para cache.
     */
    public static function open(): string
    {
        self::enqueue();

        if (AuthManager::get_current_inscricao_id() !== null && ! defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }

        ob_start();
        ?>
        <div class="festa-wrap">
          <header class="festa-header">
            <svg class="festa-bunting" viewBox="0 0 240 52" aria-hidden="true">
              <path d="M4 8 Q120 30 236 8" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"/>
              <path class="f1" d="M20 11l22 4-15 22z"/>
              <path class="f2" d="M56 17l22 3-13 23z"/>
              <path class="f3" d="M92 20l22 1-11 24z"/>
              <path class="f1" d="M126 21l22-1-11 24z"/>
              <path class="f2" d="M162 20l22-3-9 24z"/>
              <path class="f3" d="M198 15l22-4-7 23z"/>
            </svg>
            <p class="festa-eyebrow">SMDES · Edital 001/2026</p>
            <p class="festa-display">Festa dos Amigos</p>
            <p class="festa-script">2026</p>
          </header>
        <?php
        return (string) ob_get_clean() . self::notice_from_query();
    }

    public static function close(): string
    {
        return '<p class="festa-footer-note">SECRETARIA MUNICIPAL DE DESENVOLVIMENTO SOCIAL &middot; SMDES</p></div>';
    }

    /** Assinatura de um aviso levado na URL (envio sem JavaScript). */
    public static function notice_signature(string $message): string
    {
        return substr(Crypto::sign('festa_erro|' . $message), 0, 32);
    }

    /**
     * Resultado do envio tradicional (sem JavaScript), trazido na URL. Só é
     * mostrado com a assinatura do site: um link montado por terceiros com
     * um texto qualquer ("inscrição cancelada, ligue para…") é ignorado.
     */
    private static function notice_from_query(): string
    {
        $erro = isset($_GET['festa_erro']) && is_string($_GET['festa_erro']) ? wp_unslash($_GET['festa_erro']) : '';
        $sig  = isset($_GET['festa_sig']) && is_string($_GET['festa_sig']) ? $_GET['festa_sig'] : '';

        if ($erro === '' || ! hash_equals(self::notice_signature($erro), $sig)) {
            return '';
        }

        return '<p class="festa-form-error festa-form-error--static" role="alert">'
            . esc_html(sanitize_text_field($erro)) . '</p>';
    }

    /** Cartão de apresentação, com o texto das Configurações e as etapas. */
    public static function intro_card(): string
    {
        $edital = Settings::text('link_edital');

        ob_start();
        ?>
        <div class="festa-card festa-card--intro">
          <div class="festa-intro-stripe" aria-hidden="true"></div>
          <h2 class="festa-title">Inscrição de grupos</h2>
          <p class="festa-subtitle">Edital 001/2026/SMDES</p>
          <?php
          foreach ((array) preg_split('/\n\s*\n/', trim(Settings::text('apresentacao'))) as $par) {
              if (trim((string) $par) !== '') {
                  echo '<p class="festa-intro">' . nl2br(esc_html(trim((string) $par))) . '</p>';
              }
          }
          ?>
          <ul class="festa-steps" aria-label="Etapas">
            <li><span>1</span> Pré-cadastro</li>
            <li><span>2</span> Dados do grupo e documentos</li>
            <li><span>3</span> Acompanhamento pelo painel</li>
          </ul>
          <?php if ($edital !== '') : ?>
            <p><a class="festa-edital" href="<?php echo esc_url($edital); ?>" target="_blank" rel="noopener">Ler o edital ↗</a></p>
          <?php endif; ?>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /** Cartão exibido no lugar dos formulários quando as inscrições estão encerradas. */
    public static function closed_card(string $extra_html = ''): string
    {
        $opens = Settings::opens_at();
        if ($opens !== null && Settings::not_yet_open() && Settings::get('aberto')) {
            return '<div class="festa-card festa-closed"><div class="festa-closed-icon" aria-hidden="true">!</div>'
                . '<h3>Inscrições ainda não abertas</h3>'
                . '<p>' . esc_html('As inscrições abrem em ' . wp_date('d/m/Y \à\s H:i', $opens->getTimestamp()) . '.') . '</p>'
                . $extra_html
                . '</div>';
        }

        return '<div class="festa-card festa-closed"><div class="festa-closed-icon" aria-hidden="true">!</div>'
            . '<h3>Inscrições encerradas</h3>'
            . '<p>' . nl2br(esc_html(Settings::text('msg_encerrado'))) . '</p>'
            . $extra_html
            . '</div>';
    }

    public static function card_open(string $title, string $desc = '', string $extra_class = '', string $attrs = ''): string
    {
        $html = '<div class="festa-card ' . esc_attr($extra_class) . '"' . $attrs . '>'
            . '<div class="festa-section-bar">' . esc_html($title) . '</div>';

        if ($desc !== '') {
            $html .= '<p class="festa-section-desc">' . esc_html($desc) . '</p>';
        }

        return $html;
    }

    // -----------------------------------------------------------------------
    // Status
    // -----------------------------------------------------------------------

    public static function status_badge(string $status): string
    {
        return '<span class="festa-badge festa-badge--' . esc_attr($status) . '">'
            . esc_html(StateMachine::label($status)) . '</span>';
    }

    /**
     * Linha do tempo das cinco fases, com a atual em destaque.
     *
     * Lista de espera e indeferido ficam na posição da análise: a etapa
     * aparece como "Lista de espera" (atual) ou "Indeferida" (encerrada,
     * sem as seguintes).
     */
    public static function stepper(string $status): string
    {
        $steps = [
            StateMachine::STATUS_RASCUNHO     => 'Inscrição',
            StateMachine::STATUS_ANALISE      => 'Análise',
            StateMachine::STATUS_DEFERIDO     => 'Sorteio da tenda',
            StateMachine::STATUS_COMPROVANTES => 'Comprovantes',
            StateMachine::STATUS_CONCLUIDO    => 'Conclusão',
        ];
        $na_analise = in_array($status, [StateMachine::STATUS_LISTA_ESPERA, StateMachine::STATUS_INDEFERIDO], true);
        $current    = $na_analise ? 1 : (int) array_search($status, array_keys($steps), true);
        $rejected   = $status === StateMachine::STATUS_INDEFERIDO;
        $html       = '<ol class="festa-stepper' . ($rejected ? ' is-rejected' : '') . '" aria-label="Etapas da inscrição">';

        foreach (array_values($steps) as $i => $label) {
            if ($i === 1 && $status === StateMachine::STATUS_LISTA_ESPERA) {
                $label = 'Lista de espera';
            } elseif ($i === 1 && $rejected) {
                $label = 'Indeferida';
            }
            // Concluído é a última fase e já está cumprida.
            $done  = $i < $current || $status === StateMachine::STATUS_CONCLUIDO;
            $class = $done ? 'is-done' : ($i === $current ? ($rejected ? 'is-current is-rejected' : 'is-current') : '');
            $html .= '<li' . ($class ? ' class="' . $class . '"' : '') . ($i === $current ? ' aria-current="step"' : '') . '>'
                . esc_html($label) . '</li>';
        }

        return $html . '</ol>';
    }

    /**
     * Aviso de vagas esgotadas no pré-cadastro e na finalização: a
     * inscrição continua aberta, mas entra na lista de espera.
     */
    public static function vagas_esgotadas_notice(): string
    {
        if (! Vagas::esgotadas()) {
            return '';
        }

        return '<p class="festa-notice festa-notice--fila" role="status"><strong>Vagas esgotadas.</strong> '
            . 'Você ainda pode se inscrever: ao finalizar, a inscrição entra na <strong>lista de espera</strong>, '
            . 'por ordem de chegada, e é chamada se uma vaga for liberada.</p>';
    }

    // -----------------------------------------------------------------------
    // Campos
    // -----------------------------------------------------------------------

    private static function req_mark(bool $required): string
    {
        return $required ? ' <span class="festa-req" aria-hidden="true">*</span>' : '';
    }

    /**
     * Campo de texto.
     *
     * @param array{type?: string, placeholder?: string, help?: string, required?: bool, attrs?: string} $a
     *        type: text | email | cpf | phone | number | password
     */
    public static function field_text(string $name, string $label, array $a = []): string
    {
        $type     = $a['type'] ?? 'text';
        $required = $a['required'] ?? true;
        $id       = 'festa-' . $name . '-' . wp_unique_id();

        // Atributos que melhoram o preenchimento (teclado, limites, máscara).
        $attrs = match ($type) {
            'cpf'      => 'type="text" inputmode="numeric" maxlength="14" autocomplete="off" data-mask="cpf"',
            'phone'    => 'type="tel" inputmode="tel" maxlength="15" autocomplete="tel-national" data-mask="phone"',
            'email'    => 'type="email" inputmode="email" autocomplete="email" maxlength="190"',
            'number'   => 'type="number" inputmode="numeric"',
            'password' => 'type="password" maxlength="128"',
            default    => 'type="text" maxlength="120"',
        };

        ob_start();
        ?>
        <div class="festa-field">
          <label class="festa-label" for="<?php echo esc_attr($id); ?>"><?php echo esc_html($label) . self::req_mark($required); ?></label>
          <?php if (! empty($a['help'])) : ?>
            <p class="festa-help"><?php echo esc_html($a['help']); ?></p>
          <?php endif; ?>
          <input class="festa-input" id="<?php echo esc_attr($id); ?>" name="<?php echo esc_attr($name); ?>"
                 placeholder="<?php echo esc_attr($a['placeholder'] ?? ''); ?>"
                 <?php echo $required ? 'data-required="1" required' : ''; ?> <?php echo $attrs . ' ' . ($a['attrs'] ?? ''); ?>>
          <p class="festa-error" role="alert" hidden></p>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Escolha única, em forma de botões.
     *
     * @param array<int|string, string> $options valor => rótulo
     */
    public static function field_choice(string $name, string $label, array $options): string
    {
        $id = 'festa-' . $name . '-' . wp_unique_id();

        ob_start();
        ?>
        <div class="festa-field" data-required-radio="<?php echo esc_attr($name); ?>" role="radiogroup" aria-labelledby="<?php echo esc_attr($id); ?>">
          <span class="festa-label" id="<?php echo esc_attr($id); ?>"><?php echo esc_html($label) . self::req_mark(true); ?></span>
          <div class="festa-choices">
            <?php foreach ($options as $value => $text) : ?>
              <label class="festa-choice">
                <input type="radio" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr((string) $value); ?>" required>
                <span><?php echo esc_html($text); ?></span>
              </label>
            <?php endforeach; ?>
          </div>
          <p class="festa-error" role="alert" hidden></p>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Documento: área para escolher, arrastar ou fotografar, com prévia.
     *
     * @param string $accept doc (imagem ou PDF) | image | pdf
     */
    public static function field_file(string $name, string $label, string $accept = 'doc', bool $required = true, string $help = ''): string
    {
        $id   = 'festa-' . $name . '-' . wp_unique_id();
        $hint = Handler::accept_hint($accept) . ' · até ' . Handler::human_size(Handler::max_bytes());

        ob_start();
        ?>
        <div class="festa-field festa-file">
          <label class="festa-label" for="<?php echo esc_attr($id); ?>"><?php echo esc_html($label) . self::req_mark($required); ?></label>
          <?php if ($help !== '') : ?>
            <p class="festa-help"><?php echo esc_html($help); ?></p>
          <?php endif; ?>
          <div class="festa-drop">
            <input class="festa-file-input" type="file" id="<?php echo esc_attr($id); ?>" name="<?php echo esc_attr($name); ?>"
                   accept="<?php echo esc_attr(Handler::accept_attr($accept)); ?>"
                   data-accept="<?php echo esc_attr($accept); ?>" <?php echo $required ? 'data-required="1" required' : ''; ?>>
            <div class="festa-drop-empty">
              <span class="festa-drop-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" width="26" height="26"><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M12 16V4m0 0L7 9m5-5 5 5M4 16v3a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-3"/></svg>
              </span>
              <span class="festa-drop-text"><strong>Escolher arquivo</strong> <span class="festa-drop-or">ou tirar foto</span></span>
              <span class="festa-drop-hint"><?php echo esc_html($hint); ?></span>
            </div>
            <div class="festa-drop-filled" hidden>
              <img class="festa-file-thumb" alt="" hidden>
              <span class="festa-file-pdf" aria-hidden="true" hidden>PDF</span>
              <span class="festa-file-meta">
                <span class="festa-file-name"></span>
                <span class="festa-file-size"></span>
              </span>
              <button type="button" class="festa-file-remove">Trocar</button>
            </div>
          </div>
          <p class="festa-error" role="alert" hidden></p>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /** Caixa de aceite obrigatória. $links_html já deve vir escapado. */
    public static function field_check(string $name, string $text, string $links_html = ''): string
    {
        return '<div class="festa-field festa-check-field" data-required-check="' . esc_attr($name) . '">'
            . '<label class="festa-check"><input type="checkbox" name="' . esc_attr($name) . '" value="1" required> '
            . '<span>' . esc_html($text) . self::req_mark(true) . '</span></label>'
            . ($links_html !== '' ? '<p class="festa-check-links">' . $links_html . '</p>' : '')
            . '<p class="festa-error" role="alert" hidden></p>'
            . '</div>';
    }

    // -----------------------------------------------------------------------
    // Formulários
    // -----------------------------------------------------------------------

    /**
     * Abre um formulário enviado por AJAX.
     *
     * method/action de verdade: se o envio por JavaScript falhar (rede
     * instável, navegador embutido do WhatsApp), o formulário ainda envia
     * pelo caminho tradicional e volta para esta página.
     *
     * @param string $action  Action do endpoint (ver AjaxHandler).
     * @param string $success reload (padrão) | redirect | #id do painel de confirmação
     */
    public static function form_open(string $action, string $success = 'reload', bool $multipart = false): string
    {
        $panel = str_starts_with($success, '#');

        return '<form class="festa-form" method="post" novalidate data-festa-form'
            . ' action="' . esc_url(self::admin_path('admin-post.php') . '?action=' . $action) . '"'
            . ($multipart ? ' enctype="multipart/form-data"' : '')
            . ' data-festa-success="' . esc_attr($panel ? 'panel' : $success) . '"'
            . ($panel ? ' data-festa-panel="' . esc_attr($success) . '"' : '')
            . '>'
            . '<input type="hidden" name="action" value="' . esc_attr($action) . '">'
            . '<input type="hidden" name="nonce" value="' . esc_attr(wp_create_nonce('festa_auth_nonce')) . '">'
            . '<input type="hidden" name="festa_csrf" value="' . esc_attr(AuthManager::csrf_token()) . '">'
            . '<input type="hidden" name="festa_redirect" value="' . esc_url(self::current_url()) . '">';
    }

    /** Fecha o formulário, com o botão de envio e o espaço da mensagem de erro. */
    public static function form_close(string $submit_label, string $button_class = 'festa-submit'): string
    {
        return '<div class="festa-actions">'
            . '<button type="submit" class="' . esc_attr($button_class) . '">' . esc_html($submit_label) . '</button>'
            . '</div>'
            . '<p class="festa-form-error" role="alert" hidden></p>'
            . '</form>';
    }
}
