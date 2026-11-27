<?php

declare(strict_types=1);

namespace Festa\Admin;

/**
 * Contexto de gerenciamento.
 *
 * As mesmas telas e ações (lista, inscrição, ações da Comissão, busca de
 * colchões, auditoria, configurações, documentos, exportações) funcionam em
 * dois lugares: no wp-admin e no portal externo. O que muda entre os dois —
 * quem é o usuário, o que ele pode fazer, como são os endereços e como cada
 * envio é autenticado — fica atrás desta fachada. O padrão é o wp-admin; o
 * portal troca o contexto ao assumir a requisição (ver Portal\Portal).
 */
final class Ctx
{
    private static ?CtxDriver $impl = null;

    public static function set(CtxDriver $impl): void
    {
        self::$impl = $impl;
    }

    private static function impl(): CtxDriver
    {
        return self::$impl ??= new CtxAdmin();
    }

    /**
     * Permissões que um usuário pode ter. No wp-admin elas vêm das
     * capabilities das roles (ver CtxAdmin::CAPS); no portal, da conta.
     *
     * @return array<string, string>
     */
    public static function perms(): array
    {
        return [
            'ver'       => 'Ver inscrições, documentos e auditoria',
            'dashboard' => 'Ver o dashboard (só contagens, sem dados pessoais)',
            'analisar'  => 'Deferir, indeferir e reabrir inscrições; chamar da lista de espera',
            'tenda'    => 'Informar o número da tenda sorteada',
            'recibo'   => 'Aprovar o recibo da tenda',
            'colchoes' => 'Buscar por CPF e confirmar a doação de colchões',
            'editar'   => 'Observações, contato, documentos e senha do participante',
            'exportar' => 'Exportar CSV e PDF',
            'excluir'  => 'Excluir inscrições',
            'config'   => 'Alterar as configurações',
        ];
    }

    public static function is_portal(): bool
    {
        return self::impl()->is_portal();
    }

    public static function can(string $perm): bool
    {
        return self::impl()->can($perm);
    }

    /**
     * Endereço de uma tela: inscricoes | dashboard | colchoes | auditoria | config.
     *
     * @param array<string, scalar> $args
     */
    public static function url(string $screen = 'inscricoes', array $args = []): string
    {
        return self::impl()->url($screen, $args);
    }

    /**
     * Endereço de uma ação só de leitura (documento, ZIP, CSV, PDF).
     *
     * @param array<string, scalar> $args
     */
    public static function download_url(string $action, array $args = [], string $nonce = ''): string
    {
        return self::impl()->download_url($action, $args, $nonce);
    }

    /** Para onde vão os formulários que alteram dados (sempre POST). */
    public static function form_action(): string
    {
        return self::impl()->form_action();
    }

    /**
     * Campos ocultos de um formulário de alteração: a ação e o token que
     * prova que o envio partiu desta sessão.
     */
    public static function form_fields(string $action, string $nonce): string
    {
        return self::impl()->form_fields($action, $nonce);
    }

    /** Destino e campos ocultos dos formulários de busca/filtro (GET). */
    public static function filter_action(): string
    {
        return self::impl()->filter_action();
    }

    public static function filter_fields(string $screen): string
    {
        return self::impl()->filter_fields($screen);
    }

    /**
     * Exige a permissão (e, no wp-admin, o nonce) antes de uma ação.
     * Encerra a requisição se não passar.
     */
    public static function guard(string $perm, string $nonce): void
    {
        self::impl()->guard($perm, $nonce);
    }

    /** Ações que alteram dados só são aceitas por POST (nunca por um link). */
    public static function require_post(): void
    {
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
            self::stop('Método não permitido.', 405);
        }
    }

    /** Encerra a requisição com uma mensagem de erro. */
    public static function stop(string $message, int $code = 403): never
    {
        self::impl()->stop($message, $code);
        exit;
    }

    public static function redirect(string $url): never
    {
        self::impl()->redirect($url);
        exit;
    }

    /** Nome gravado na auditoria ('' = usuário do WordPress). */
    public static function actor(): string
    {
        return self::impl()->actor();
    }

    /** Identifica o usuário atual (WordPress ou conta do portal) em chaves internas. */
    public static function user_key(): string
    {
        return self::impl()->user_key();
    }

    // -----------------------------------------------------------------------
    // Avisos de uma única exibição
    // -----------------------------------------------------------------------

    private static function flash_key(string $name): string
    {
        return 'festa_flash_' . $name . '_' . md5(self::impl()->user_key());
    }

    /**
     * Guarda um valor só até a próxima tela de quem fez a ação (no máximo
     * 5 min): a mensagem de erro de um envio, uma senha recém-gerada.
     */
    public static function flash_set(string $name, mixed $value): void
    {
        set_transient(self::flash_key($name), $value, 5 * MINUTE_IN_SECONDS);
    }

    /** Lê e apaga o valor guardado (null se não houver). */
    public static function flash_get(string $name): mixed
    {
        $key   = self::flash_key($name);
        $value = get_transient($key);

        if ($value === false) {
            return null;
        }
        delete_transient($key);

        return $value;
    }
}
