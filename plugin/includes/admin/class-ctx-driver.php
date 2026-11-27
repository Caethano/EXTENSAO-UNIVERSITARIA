<?php

declare(strict_types=1);

namespace Festa\Admin;

/**
 * Contrato de um contexto de gerenciamento (wp-admin ou portal externo).
 * Ver {@see Ctx}.
 */
interface CtxDriver
{
    public function is_portal(): bool;

    public function can(string $perm): bool;

    /** @param array<string, scalar> $args */
    public function url(string $screen, array $args): string;

    /** @param array<string, scalar> $args */
    public function download_url(string $action, array $args, string $nonce): string;

    public function form_action(): string;

    public function form_fields(string $action, string $nonce): string;

    public function filter_action(): string;

    public function filter_fields(string $screen): string;

    public function guard(string $perm, string $nonce): void;

    public function stop(string $message, int $code): void;

    public function redirect(string $url): void;

    public function actor(): string;

    /** Identificador único de quem está usando a tela (para avisos de uma única exibição). */
    public function user_key(): string;
}
