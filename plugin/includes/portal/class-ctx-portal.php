<?php

declare(strict_types=1);

namespace Festa\Portal;

use Festa\Admin\CtxDriver;

/**
 * Contexto do portal: conta do portal, endereços do portal e token CSRF.
 */
final class CtxPortal implements CtxDriver
{
    public function is_portal(): bool
    {
        return true;
    }

    public function can(string $perm): bool
    {
        return (bool) Portal::can($perm);
    }

    public function url(string $screen, array $args): string
    {
        return Portal::url(['tela' => $screen] + $args);
    }

    /**
     * Downloads são só leitura: basta a sessão (o cookie não acompanha
     * requisições vindas de outros sites). Sem token no endereço, nada
     * sensível fica em histórico, favoritos ou logs do servidor.
     */
    public function download_url(string $action, array $args, string $nonce): string
    {
        return Portal::url(['baixar' => $action] + $args);
    }

    public function form_action(): string
    {
        return Portal::url();
    }

    public function form_fields(string $action, string $nonce): string
    {
        return '<input type="hidden" name="festa_acao" value="' . esc_attr($action) . '">'
            . '<input type="hidden" name="_csrf" value="' . esc_attr(Portal::csrf_token()) . '">';
    }

    public function filter_action(): string
    {
        return Portal::pretty() ? Portal::url() : home_url('/');
    }

    public function filter_fields(string $screen): string
    {
        return (Portal::pretty() ? '' : '<input type="hidden" name="festa_portal" value="1">')
            . '<input type="hidden" name="tela" value="' . esc_attr($screen) . '">';
    }

    public function guard(string $perm, string $nonce): void
    {
        if (! Portal::user()) {
            Portal::stop('Sua sessão terminou. Entre novamente.', 401);
        }

        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($method === 'POST' && ! Portal::csrf_ok()) {
            Portal::stop('Não foi possível confirmar o envio.', 403);
        }
        if (! Portal::can($perm)) {
            Portal::stop('Seu usuário não tem permissão para esta ação.', 403);
        }
    }

    public function stop(string $message, int $code): void
    {
        Portal::stop($message, $code);
    }

    /**
     * Depois de uma ação, só volta para dentro do próprio portal.
     */
    public function redirect(string $url): void
    {
        wp_safe_redirect(Portal::safe_url($url), 303);
    }

    public function actor(): string
    {
        $user = Portal::user();

        return $user ? $user['nome'] . ' (portal)' : '';
    }

    public function user_key(): string
    {
        $user = Portal::user();

        return 'portal' . ($user ? (int) $user['id'] : 0);
    }
}
