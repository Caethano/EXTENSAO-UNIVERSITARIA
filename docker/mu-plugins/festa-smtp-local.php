<?php
/**
 * Plugin Name: E-mail local (Mailpit)
 * Description: Entrega os e-mails do WordPress ao Mailpit do docker compose, em vez de tentar enviá-los de verdade. Só age quando a variável SMTP_HOST está definida.
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

$festa_smtp_host = (string) getenv('SMTP_HOST');

if ($festa_smtp_host !== '') {
    add_action('phpmailer_init', static function ($mail) use ($festa_smtp_host): void {
        $mail->isSMTP();
        $mail->Host        = $festa_smtp_host;
        $mail->Port        = (int) (getenv('SMTP_PORT') ?: 1025);
        $mail->SMTPAuth    = false;
        $mail->SMTPAutoTLS = false;
    });

    // Em "localhost" o remetente padrão vira wordpress@localhost, que o próprio
    // WordPress recusa (domínio sem ponto) e o e-mail nem sai.
    add_filter('wp_mail_from', static function (string $from): string {
        return is_email($from) ? $from : 'nao-responda@festa.local';
    });
}

unset($festa_smtp_host);
