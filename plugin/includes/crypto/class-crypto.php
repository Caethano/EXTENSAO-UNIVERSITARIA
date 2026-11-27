<?php

declare(strict_types=1);

namespace Festa\Crypto;

use InvalidArgumentException;
use RuntimeException;

/**
 * Utilitário criptográfico do plugin.
 *
 * Responsabilidades:
 *  - Criptografar / descriptografar dados sensíveis (CPF) via AES-256-CBC.
 *  - Gerar hash HMAC-SHA256 para queries de busca sem expor o dado em plaintext.
 *  - Mascarar CPF para exibição na UI seguindo política LGPD.
 *
 * As chaves ficam FORA do banco de dados — uma cópia do banco sozinha não
 * decifra os CPFs. Ordem de procura:
 *   1. Constantes FESTA_ENC_KEY e FESTA_HMAC_KEY no wp-config.php (recomendado).
 *   2. Arquivo de chaves (FESTA_KEYS_FILE), gerado pelo plugin na pasta
 *      privada dos documentos, fora do alcance do navegador.
 *
 * Se o banco tiver chaves nas opções festa_enc_key e festa_hmac_key, a
 * instalação/atualização copia os mesmos valores para o arquivo e apaga as
 * opções — os CPFs já cifrados continuam legíveis.
 */
final class Crypto
{
    private const CIPHER    = 'aes-256-cbc';
    private const IV_LENGTH = 16; // AES CBC block size
    private const SEPARATOR = ':';

    private const LEGACY_OPTIONS = ['enc' => 'festa_enc_key', 'hmac' => 'festa_hmac_key'];

    /** @var array{enc: string, hmac: string}|null Chaves já carregadas nesta requisição. */
    private static ?array $keys = null;

    // -----------------------------------------------------------------------
    // Criptografia simétrica
    // -----------------------------------------------------------------------

    /**
     * Cifra $raw_data com AES-256-CBC usando IV único por operação.
     *
     * Formato de retorno: base64(IV) : base64(ciphertext)
     *
     * @throws RuntimeException Se a chave não estiver definida ou openssl falhar.
     */
    public static function encrypt(string $raw_data): string
    {
        $key = self::require_encryption_key();
        $iv  = random_bytes(self::IV_LENGTH);

        $ciphertext = openssl_encrypt(
            $raw_data,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv
        );

        if ($ciphertext === false) {
            throw new RuntimeException(
                '[Festa\Crypto] openssl_encrypt falhou. Verifique a extensão openssl.'
            );
        }

        return base64_encode($iv) . self::SEPARATOR . base64_encode($ciphertext);
    }

    /**
     * Decifra payload gerado por encrypt().
     *
     * @throws InvalidArgumentException Se o formato do payload for inválido.
     * @throws RuntimeException         Se a descriptografia falhar (chave incorreta, dado corrompido).
     */
    public static function decrypt(string $payload): string
    {
        $key  = self::require_encryption_key();
        $parts = explode(self::SEPARATOR, $payload, 2);

        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw new InvalidArgumentException(
                '[Festa\Crypto] Payload inválido: esperado formato "base64(IV):base64(ciphertext)".'
            );
        }

        $iv         = base64_decode($parts[0], strict: true);
        $ciphertext = base64_decode($parts[1], strict: true);

        if ($iv === false || $ciphertext === false) {
            throw new InvalidArgumentException(
                '[Festa\Crypto] Falha no base64_decode: payload corrompido ou adulterado.'
            );
        }

        if (strlen($iv) !== self::IV_LENGTH) {
            throw new InvalidArgumentException(
                sprintf(
                    '[Festa\Crypto] IV com tamanho inválido (%d bytes; esperado %d).',
                    strlen($iv),
                    self::IV_LENGTH
                )
            );
        }

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv
        );

        if ($plaintext === false) {
            throw new RuntimeException(
                '[Festa\Crypto] openssl_decrypt falhou. Chave incorreta ou dado corrompido.'
            );
        }

        return $plaintext;
    }

    // -----------------------------------------------------------------------
    // Hash pesquisável (para _cpf_hash)
    // -----------------------------------------------------------------------

    /**
     * Gera HMAC-SHA256 de $raw_data para armazenamento como índice de busca.
     * O CPF real nunca sai em plaintext do banco; queries usam este hash.
     *
     * @throws RuntimeException Se FESTA_HMAC_KEY não estiver definida.
     */
    public static function hash(string $raw_data): string
    {
        return hash_hmac('sha256', $raw_data, self::require_hmac_key());
    }

    /**
     * Assinatura HMAC-SHA256 de uma mensagem (URLs de download, avisos na
     * URL). Mesma chave e algoritmo de hash(): links já emitidos continuam
     * válidos.
     */
    public static function sign(string $message): string
    {
        return hash_hmac('sha256', $message, self::require_hmac_key());
    }

    // -----------------------------------------------------------------------
    // Máscara LGPD para exibição
    // -----------------------------------------------------------------------

    /**
     * Mascara CPF para exibição segura na UI.
     *
     * Entrada: "12345678900" ou "123.456.789-00"
     * Saída:   "***.456.789-**"
     *
     * @throws InvalidArgumentException Se a entrada não contiver exatamente 11 dígitos.
     */
    public static function mask_cpf(string $cpf_raw): string
    {
        $digits = preg_replace('/\D/', '', $cpf_raw);

        if (strlen((string) $digits) !== 11) {
            throw new InvalidArgumentException(
                '[Festa\Crypto] CPF inválido para mascaramento: deve conter exatamente 11 dígitos.'
            );
        }

        return sprintf(
            '***.%s.%s-**',
            substr((string) $digits, 3, 3),
            substr((string) $digits, 6, 3)
        );
    }

    // -----------------------------------------------------------------------
    // Chaves
    // -----------------------------------------------------------------------

    /** Caminho do arquivo de chaves (configurável no wp-config.php). */
    public static function keys_file(): string
    {
        return defined('FESTA_KEYS_FILE')
            ? (string) FESTA_KEYS_FILE
            : rtrim(FESTA_UPLOADS_DIR, '/\\') . '/festa-chaves.php';
    }

    /**
     * Garante que as chaves existem fora do banco. Executado na ativação e a
     * cada atualização do plugin (Plugin::install()):
     *  - com as constantes no wp-config.php, só apaga do banco as opções
     *    festa_enc_key/festa_hmac_key de mesmo valor;
     *  - sem arquivo, cria-o com as chaves dessas opções, se existirem, ou,
     *    numa instalação nova, com chaves sorteadas;
     *  - por fim, apaga do banco as opções que já estão no arquivo.
     *
     * @throws RuntimeException Se o arquivo não puder ser criado.
     */
    public static function ensure_keys(): void
    {
        self::$keys = null;
        $legacy     = self::legacy_keys();
        $current    = self::constant_keys() ?? self::file_keys();

        if ($current === null) {
            $novas = $legacy ?? [
                'enc'  => wp_generate_password(64, true, true),
                'hmac' => wp_generate_password(64, true, true),
            ];
            self::write_keys_file($novas);
            $current = self::file_keys();
            if ($current === null) {
                throw new RuntimeException(sprintf(
                    '[Festa\Crypto] Não foi possível criar o arquivo de chaves em %s. '
                    . 'Defina FESTA_ENC_KEY e FESTA_HMAC_KEY no wp-config.php ou libere a gravação na pasta.',
                    self::keys_file()
                ));
            }
        }

        // Só apaga do banco o que já está guardado fora dele, com o mesmo valor.
        foreach (self::LEGACY_OPTIONS as $k => $option) {
            $old = get_option($option, '');
            if (is_string($old) && $old !== '') {
                if (hash_equals($current[$k], $old)) {
                    delete_option($option);
                } else {
                    error_log(sprintf(
                        '[Festa\Crypto] A opção %s difere da chave em uso e foi mantida no banco: confira antes de apagá-la.',
                        $option
                    ));
                }
            }
        }

        self::$keys = $current;
    }

    /**
     * Chaves em uso: constantes, depois o arquivo e, só durante a atualização
     * (antes de ensure_keys() rodar), as opções do banco.
     *
     * @return array{enc: string, hmac: string}
     * @throws RuntimeException Se nenhuma chave for encontrada.
     */
    private static function keys(): array
    {
        if (self::$keys !== null) {
            return self::$keys;
        }

        $keys = self::constant_keys() ?? self::file_keys() ?? self::legacy_keys();
        if ($keys === null) {
            throw new RuntimeException(
                '[Festa\Crypto] Chaves de criptografia não encontradas. Reative o plugin para gerá-las '
                . '(ou restaure o arquivo ' . self::keys_file() . ' do backup).'
            );
        }

        return self::$keys = $keys;
    }

    /** @return array{enc: string, hmac: string}|null */
    private static function constant_keys(): ?array
    {
        if (! defined('FESTA_ENC_KEY') || ! defined('FESTA_HMAC_KEY')) {
            return null;
        }

        $enc  = (string) constant('FESTA_ENC_KEY');
        $hmac = (string) constant('FESTA_HMAC_KEY');

        return $enc !== '' && $hmac !== '' ? ['enc' => $enc, 'hmac' => $hmac] : null;
    }

    /** @return array{enc: string, hmac: string}|null */
    private static function file_keys(): ?array
    {
        $file = self::keys_file();
        if (! is_file($file) || ! is_readable($file)) {
            return null;
        }

        $data = include $file;

        return is_array($data) && is_string($data['enc'] ?? null) && is_string($data['hmac'] ?? null)
            && $data['enc'] !== '' && $data['hmac'] !== ''
            ? ['enc' => $data['enc'], 'hmac' => $data['hmac']]
            : null;
    }

    /** @return array{enc: string, hmac: string}|null */
    private static function legacy_keys(): ?array
    {
        $enc  = get_option(self::LEGACY_OPTIONS['enc'], '');
        $hmac = get_option(self::LEGACY_OPTIONS['hmac'], '');

        return is_string($enc) && is_string($hmac) && $enc !== '' && $hmac !== ''
            ? ['enc' => $enc, 'hmac' => $hmac]
            : null;
    }

    /**
     * Grava o arquivo de chaves sem sobrescrever um já existente: o conteúdo
     * vai para um temporário e é ligado ao nome final de uma vez (link() falha
     * se o arquivo já existir) — duas ativações simultâneas nunca gravam
     * chaves diferentes.
     *
     * @param array{enc: string, hmac: string} $keys
     */
    private static function write_keys_file(array $keys): void
    {
        $file = self::keys_file();
        $dir  = dirname($file);

        if ($dir === rtrim(FESTA_UPLOADS_DIR, '/\\')) {
            \Festa\Upload\Handler::protect_dir();
        } elseif (! is_dir($dir)) {
            wp_mkdir_p($dir);
        }

        $php = "<?php\n"
            . "// Festa dos Amigos 2026 — chaves de criptografia dos CPFs e das assinaturas.\n"
            . "// NÃO APAGUE nem altere: sem estas chaves os CPFs gravados ficam ilegíveis.\n"
            . "// Inclua este arquivo nos backups, guardado separado do backup do banco.\n"
            . "defined('ABSPATH') || exit;\n"
            . 'return ' . var_export(['enc' => $keys['enc'], 'hmac' => $keys['hmac']], true) . ";\n";

        $tmp = $file . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (@file_put_contents($tmp, $php) === false) {
            return;
        }
        if (! defined('WP_CLI')) {
            @chmod($tmp, 0640);
        }
        if (! @link($tmp, $file) && ! is_file($file)) {
            @rename($tmp, $file);
        }
        @unlink($tmp);
    }

    /**
     * Retorna a chave de 32 bytes para AES-256.
     * Normaliza para 32 bytes via SHA-256 raw para aceitar chaves de qualquer tamanho,
     * preservando toda a entropia do valor gerado na ativação do plugin.
     *
     * @throws RuntimeException Se a chave não for encontrada.
     */
    private static function require_encryption_key(): string
    {
        // hash(..., raw_output: true) → 32 bytes exatos requeridos pelo AES-256
        return hash('sha256', self::keys()['enc'], true);
    }

    /**
     * @throws RuntimeException Se a chave não for encontrada.
     */
    private static function require_hmac_key(): string
    {
        return self::keys()['hmac'];
    }
}
