<?php

declare(strict_types=1);

namespace Festa;

/**
 * Autoloader PSR-4 minimalista sem Composer.
 *
 * Convenção de mapeamento: Festa\<SubNs>\<ClassName>
 *   → FESTA_PLUGIN_DIR/includes/<sub-ns>/class-<class-name-em-kebab>.php
 *
 * A conversão de PascalCase para kebab-case usa apenas o nome da CLASSE
 * (última parte do namespace), não o namespace completo. Assim:
 *
 *   Festa\Plugin              → includes/class-plugin.php
 *   Festa\Roles\Roles         → includes/roles/class-roles.php
 *   Festa\Crypto\Crypto       → includes/crypto/class-crypto.php
 *   Festa\Pdf\Generator       → includes/pdf/class-generator.php
 *   Festa\Upload\Handler      → includes/upload/class-handler.php
 *   Festa\Status\StateMachine → includes/status/class-state-machine.php
 *   Festa\Cpt\CptInscricao    → includes/cpt/class-cpt-inscricao.php
 *   Festa\Audit\AuditLog      → includes/audit/class-audit-log.php
 */
final class Autoloader
{
    private const NAMESPACE_PREFIX = 'Festa\\';

    public static function register(): void
    {
        spl_autoload_register([self::class, 'load'], true, true);
    }

    private static function load(string $class): void
    {
        // Ignora qualquer namespace fora do nosso prefixo.
        if (! str_starts_with($class, self::NAMESPACE_PREFIX)) {
            return;
        }

        // Remove o prefixo e separa as partes.
        $relative   = substr($class, strlen(self::NAMESPACE_PREFIX));
        $parts      = explode('\\', $relative);
        $class_name = (string) array_pop($parts); // ex: "StateMachine"

        // Converte PascalCase para kebab-case usando preg_replace_callback,
        // mais explícito e à prova de edge cases do que lookbehind sobre ^.
        // Insere "-" antes de cada letra maiúscula que não seja a primeira.
        $kebab = strtolower(
            ltrim(
                (string) preg_replace_callback(
                    '/[A-Z]/',
                    static function (array $m): string {
                        return '-' . $m[0];
                    },
                    $class_name
                ),
                '-'
            )
        );

        $filename = 'class-' . $kebab . '.php';

        // Sub-namespaces mapeiam para subdiretórios em minúsculas.
        $subdir = $parts
            ? strtolower(implode(DIRECTORY_SEPARATOR, $parts)) . DIRECTORY_SEPARATOR
            : '';

        $path = FESTA_PLUGIN_DIR . 'includes' . DIRECTORY_SEPARATOR . $subdir . $filename;

        if (file_exists($path)) {
            require_once $path;
            return;
        }

        // Registra no log de debug quando um arquivo não é encontrado.
        // Visível apenas com WP_DEBUG_LOG=true; silencioso em produção normal.
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                '[Festa Autoloader] Arquivo não encontrado para "%s": %s',
                $class,
                $path
            ));
        }
    }
}

Autoloader::register();
