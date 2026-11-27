<?php

declare(strict_types=1);

namespace Festa\Upload;

use Festa\Crypto\Crypto;
use Festa\Fields\Fields;
use Festa\Frontend\AuthManager;
use RuntimeException;

/**
 * Pipeline de upload seguro e controller de download com URLs assinadas.
 *
 * Fluxo de upload:
 *   $_FILES → erro nativo → tamanho → tipo real (assinatura do arquivo)
 *           → UUID → move → meta do arquivo + meta de informações
 *
 * Fluxo de download:
 *   - Participante: URL assinada (HMAC) → expiração → sessão dona da inscrição → stream
 *   - Equipe: tela de gerenciamento (Admin\Panel), com permissão e nonce/sessão
 *
 * Os arquivos ficam em FESTA_UPLOADS_DIR/{inscricao_id}/{uuid}.{ext}, fora do
 * alcance direto do navegador. O meta guarda só o nome do arquivo.
 */
final class Handler
{
    private const MAX_BYTES   = 10 * 1024 * 1024; // 10 MB por arquivo
    private const AJAX_ACTION = 'festa_download_secure';

    /** Extensão canônica → Content-Type. */
    private const MIMES = [
        'pdf'  => 'application/pdf',
        'jpg'  => 'image/jpeg',
        'png'  => 'image/png',
        'webp' => 'image/webp',
        'heic' => 'image/heic',
        'html' => 'text/html; charset=UTF-8',
    ];

    // -----------------------------------------------------------------------
    // Bootstrap
    // -----------------------------------------------------------------------

    public static function boot_hooks(): void
    {
        // Ambos os hooks: o participante não é usuário do WordPress; o
        // gatekeeper interno trata a autorização por sessão ou capability.
        add_action('wp_ajax_'        . self::AJAX_ACTION, [self::class, 'serve_file']);
        add_action('wp_ajax_nopriv_' . self::AJAX_ACTION, [self::class, 'serve_file']);
    }

    // -----------------------------------------------------------------------
    // Limites e tipos aceitos
    // -----------------------------------------------------------------------

    /** Tamanho máximo por arquivo, respeitando o limite do servidor. */
    public static function max_bytes(): int
    {
        $server = (int) wp_max_upload_size();

        return $server > 0 ? min(self::MAX_BYTES, $server) : self::MAX_BYTES;
    }

    /** Tamanho máximo do envio inteiro (post_max_size), em bytes. 0 = sem limite. */
    public static function max_post_bytes(): int
    {
        return (int) wp_convert_hr_to_bytes((string) ini_get('post_max_size'));
    }

    public static function human_size(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return number_format_i18n($bytes / 1048576, 1) . ' MB';
        }

        return number_format_i18n(max(1, (int) round($bytes / 1024))) . ' KB';
    }

    /**
     * Extensões aceitas para cada tipo de campo: doc | image | pdf.
     *
     * @return list<string>
     */
    public static function allowed(string $accept): array
    {
        return match ($accept) {
            'image' => ['jpg', 'png', 'webp', 'heic'],
            'pdf'   => ['pdf'],
            default => ['pdf', 'jpg', 'png', 'webp', 'heic'],
        };
    }

    /** Valor do atributo accept do <input type="file">. */
    public static function accept_attr(string $accept): string
    {
        $img = 'image/jpeg,image/png,image/webp,image/heic,image/heif,.jpg,.jpeg,.png,.webp,.heic,.heif';
        $pdf = 'application/pdf,.pdf';

        return match ($accept) {
            'image' => $img,
            'pdf'   => $pdf,
            default => $img . ',' . $pdf,
        };
    }

    public static function accept_hint(string $accept): string
    {
        return match ($accept) {
            'image' => 'Foto (JPG, PNG, WEBP ou HEIC)',
            'pdf'   => 'PDF',
            default => 'Imagem (JPG, PNG, WEBP, HEIC) ou PDF',
        };
    }

    /**
     * Identifica o tipo real pelo conteúdo (assinatura do arquivo), sem
     * confiar na extensão nem no Content-Type enviados pelo navegador.
     *
     * @return string Extensão canônica ou ''.
     */
    public static function sniff(string $path): string
    {
        $fh = @fopen($path, 'rb');
        if (! $fh) {
            return '';
        }
        $head = (string) fread($fh, 1024);
        fclose($fh);

        if (str_contains($head, '%PDF-')) {
            return 'pdf';
        }
        if (str_starts_with($head, "\xFF\xD8\xFF")) {
            return 'jpg';
        }
        if (str_starts_with($head, "\x89PNG\r\n\x1A\n")) {
            return 'png';
        }
        if (substr($head, 0, 4) === 'RIFF' && substr($head, 8, 4) === 'WEBP') {
            return 'webp';
        }
        if (
            substr($head, 4, 4) === 'ftyp'
            && in_array(substr($head, 8, 4), ['heic', 'heix', 'hevc', 'hevx', 'heim', 'heis', 'mif1', 'msf1'], true)
        ) {
            return 'heic';
        }

        return '';
    }

    public static function mime(string $ext): string
    {
        return self::MIMES[$ext] ?? 'application/octet-stream';
    }

    // -----------------------------------------------------------------------
    // M5.1 — Upload
    // -----------------------------------------------------------------------

    /**
     * Arquivo enviado neste campo do formulário, ou null se nenhum foi escolhido.
     *
     * @return array{name: string, tmp_name: string, type: string, size: int, error: int}|null
     */
    public static function uploaded(string $field): ?array
    {
        $file = $_FILES[$field] ?? null;

        if (! is_array($file) || is_array($file['error'] ?? null) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        return $file;
    }

    /**
     * Valida um arquivo recebido.
     *
     * @param array<string, mixed> $file Entrada de $_FILES['campo'].
     * @return string|array{ext: string} Mensagem de erro, ou a extensão real se válido.
     */
    public static function check(array $file, string $accept, string $label): string|array
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        $tmp   = (string) ($file['tmp_name'] ?? '');
        $max   = self::human_size(self::max_bytes());

        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            return sprintf('"%s": o arquivo passa do limite de %s.', $label, $max);
        }
        if ($error !== UPLOAD_ERR_OK || $tmp === '' || ! is_uploaded_file($tmp)) {
            return sprintf('"%s": falha ao receber o arquivo. Tente enviar novamente.', $label);
        }

        $size = (int) ($file['size'] ?? 0);
        if ($size === 0) {
            return sprintf('"%s": o arquivo está vazio.', $label);
        }
        if ($size > self::max_bytes()) {
            return sprintf('"%s": o arquivo passa do limite de %s.', $label, $max);
        }

        $ext = self::sniff($tmp);
        if ($ext === '' || ! in_array($ext, self::allowed($accept), true)) {
            return sprintf('"%s": formato não aceito. Envie %s.', $label, strtolower(self::accept_hint($accept)));
        }

        return ['ext' => $ext];
    }

    /**
     * Valida, renomeia e persiste um documento enviado.
     *
     * @param array<string, mixed> $file         Entrada de $_FILES['campo'].
     * @param int                  $inscricao_id ID do CPT inscricao_festa.
     * @param string               $meta_key     Meta a ser atualizada (ex: '_upload_ficha_assinada').
     *
     * @return string Nome do arquivo gerado (ex: "a1b2c3d4-…-e5f6.pdf").
     *
     * @throws RuntimeException Com uma mensagem que pode ser exibida a quem enviou.
     */
    public static function handle_upload(
        array  $file,
        int    $inscricao_id,
        string $meta_key
    ): string {
        $doc    = Fields::document(Fields::document_key($meta_key));
        $label  = $doc['label'] ?? 'Documento';
        $accept = ($doc['accept'] ?? '') !== '' ? $doc['accept'] : 'doc';

        // 1–3. Erro nativo, tamanho e tipo real (ignora $file['type'] completamente)
        $check = self::check($file, $accept, $label);
        if (is_string($check)) {
            throw new RuntimeException($check);
        }

        // 4. Nome UUID v4 + extensão do tipo real (ofusca conteúdo e origem)
        $filename = self::uuid_v4() . '.' . $check['ext'];

        // 5. Subdiretório por inscrição (isola arquivos entre inscrições)
        $target_dir = self::ensure_dir($inscricao_id);

        // 6. Mover arquivo (move_uploaded_file é a única função segura para isso)
        $target_path = $target_dir . DIRECTORY_SEPARATOR . $filename;
        if (! move_uploaded_file((string) $file['tmp_name'], $target_path)) {
            error_log(sprintf('[Festa\Upload] Falha ao mover arquivo para: %s', $target_path));
            throw new RuntimeException('Não foi possível salvar o documento no servidor. Tente novamente.');
        }
        @chmod($target_path, 0640);

        // 7. Ao substituir, o arquivo anterior sai do disco
        $previous = (string) get_post_meta($inscricao_id, $meta_key, true);

        // 8. Persistir o nome no meta (não o path absoluto — portabilidade + segurança)
        update_post_meta($inscricao_id, $meta_key, $filename);
        update_post_meta($inscricao_id, $meta_key . '_info', [
            'name'        => sanitize_file_name(wp_basename((string) ($file['name'] ?? ''))) ?: $filename,
            'size'        => (int) filesize($target_path),
            'uploaded_at' => current_time('mysql'),
        ]);

        if ($previous !== '' && $previous !== $filename) {
            self::delete_file($inscricao_id, $previous);
        }

        return $filename;
    }

    // -----------------------------------------------------------------------
    // Leitura
    // -----------------------------------------------------------------------

    private static function dir(int $inscricao_id): string
    {
        return rtrim(FESTA_UPLOADS_DIR, '/\\') . DIRECTORY_SEPARATOR . $inscricao_id;
    }

    /**
     * Diretório da inscrição, criado na hora se ainda não existir — com a
     * raiz protegida antes de receber o primeiro arquivo.
     *
     * @throws RuntimeException Se o diretório não puder ser criado.
     */
    public static function ensure_dir(int $inscricao_id): string
    {
        if (! is_file(FESTA_UPLOADS_DIR . '/.htaccess')) {
            self::protect_dir();
        }

        $dir = self::dir($inscricao_id);
        if (! is_dir($dir) && ! wp_mkdir_p($dir)) {
            error_log(sprintf('[Festa\Upload] Não foi possível criar o diretório: %s', $dir));
            throw new RuntimeException('Não foi possível salvar o documento no servidor. Tente novamente.');
        }

        return $dir;
    }

    /**
     * Caminho absoluto seguro de um arquivo da inscrição ('' se não existir).
     * basename() + realpath() garantem que o arquivo está estritamente dentro
     * do diretório da inscrição (anti directory traversal).
     */
    private static function resolve(int $inscricao_id, string $filename): string
    {
        if ($inscricao_id <= 0 || $filename === '') {
            return '';
        }

        $target_dir = self::dir($inscricao_id);
        $real_dir   = realpath($target_dir);
        $real_path  = realpath($target_dir . DIRECTORY_SEPARATOR . basename($filename));

        if (
            $real_dir === false
            || $real_path === false
            || ! str_starts_with($real_path, $real_dir . DIRECTORY_SEPARATOR)
            || ! is_file($real_path)
            || ! is_readable($real_path)
        ) {
            return '';
        }

        return $real_path;
    }

    /** Caminho do documento guardado em um meta ('' se não enviado ou ausente do disco). */
    public static function path(int $inscricao_id, string $meta_key): string
    {
        return self::resolve($inscricao_id, (string) get_post_meta($inscricao_id, $meta_key, true));
    }

    /**
     * Informações de um documento da inscrição, ou null se não foi enviado.
     *
     * @return array{file: string, ext: string, mime: string, name: string, size: int, uploaded_at: string, path: string}|null
     */
    public static function info(int $inscricao_id, string $meta_key): ?array
    {
        $filename = (string) get_post_meta($inscricao_id, $meta_key, true);
        if ($filename === '') {
            return null;
        }

        $path   = self::resolve($inscricao_id, $filename);
        $stored = get_post_meta($inscricao_id, $meta_key . '_info', true);
        $stored = is_array($stored) ? $stored : [];
        $ext    = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        // Documentos sem o meta de informações (como os gerados pelo
        // sistema): os dados vêm do próprio arquivo.
        $mtime = $path !== '' ? (int) filemtime($path) : 0;

        return [
            'file'        => basename($filename),
            'ext'         => $ext,
            'mime'        => self::mime($ext),
            'name'        => (string) ($stored['name'] ?? (Fields::document_label(Fields::document_key($meta_key)) . '.' . $ext)),
            'size'        => (int) ($stored['size'] ?? ($path !== '' ? filesize($path) : 0)),
            'uploaded_at' => (string) ($stored['uploaded_at'] ?? ($mtime ? wp_date('Y-m-d H:i:s', $mtime) : '')),
            'path'        => $path,
        ];
    }

    /** Imagem que o navegador consegue exibir (miniatura no painel)? */
    public static function is_previewable(string $ext): bool
    {
        return in_array($ext, ['jpg', 'png', 'webp'], true);
    }

    /** Nome de download legível: "0123 - Recibo da tenda.pdf". */
    public static function download_name(int $inscricao_id, string $meta_key, string $ext): string
    {
        $label = remove_accents(Fields::document_label(Fields::document_key($meta_key)));

        return sanitize_file_name(str_pad((string) $inscricao_id, 4, '0', STR_PAD_LEFT) . ' - ' . $label) . '.' . $ext;
    }

    // -----------------------------------------------------------------------
    // Exclusão
    // -----------------------------------------------------------------------

    private static function delete_file(int $inscricao_id, string $filename): void
    {
        $path = self::resolve($inscricao_id, $filename);
        if ($path !== '') {
            wp_delete_file($path);
        }
    }

    /** Remove do disco todos os documentos de uma inscrição (ao excluí-la). */
    public static function delete_all(int $inscricao_id): void
    {
        if ($inscricao_id <= 0) {
            return;
        }

        $real_base = realpath(FESTA_UPLOADS_DIR);
        $real_dir  = realpath(self::dir($inscricao_id));

        if ($real_base === false || $real_dir === false || ! str_starts_with($real_dir, $real_base . DIRECTORY_SEPARATOR)) {
            return;
        }

        foreach ((array) glob($real_dir . DIRECTORY_SEPARATOR . '*') as $file) {
            if (is_file((string) $file)) {
                wp_delete_file((string) $file);
            }
        }
        @rmdir($real_dir);
    }

    // -----------------------------------------------------------------------
    // Proteção do diretório
    // -----------------------------------------------------------------------

    /**
     * Cria o diretório privado e (re)grava as proteções contra acesso direto:
     * .htaccess (Apache 2.2 e 2.4), web.config (IIS) e index.php.
     * As regras são sempre sobrescritas — uma edição manual acidental não
     * consegue enfraquecer o bloqueio.
     */
    public static function protect_dir(): bool
    {
        $dir = FESTA_UPLOADS_DIR;

        if (! is_dir($dir) && ! wp_mkdir_p($dir)) {
            return false;
        }

        $files = [
            '.htaccess'  => "# Festa dos Amigos — documentos das inscrições (servidos só via PHP)\n"
                . "Options -Indexes\n"
                . "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n"
                . "<IfModule !mod_authz_core.c>\n\tOrder deny,allow\n\tDeny from all\n</IfModule>\n",
            'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
                . "<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n",
            'index.php'  => "<?php // Silence is golden.\n",
        ];

        foreach ($files as $name => $content) {
            file_put_contents($dir . '/' . $name, $content);
        }

        return true;
    }

    // -----------------------------------------------------------------------
    // M5.3 — Signed URL via HMAC
    // -----------------------------------------------------------------------

    /**
     * Gera URL de download temporária e auto-validável via assinatura HMAC.
     * Não requer consulta ao banco para validação — toda informação está na URL.
     *
     * Formato: admin-ajax.php?action=festa_download_secure
     *          &id=X&key=_meta_key&expires=TIMESTAMP&signature=HMAC
     *
     * @param int    $inscricao_id
     * @param string $meta_key     Meta que identifica qual arquivo baixar.
     * @param int    $ttl_seconds  Validade em segundos (padrão: 10 min).
     *
     * @return string URL absoluta assinada.
     */
    public static function generate_signed_url(
        int    $inscricao_id,
        string $meta_key,
        int    $ttl_seconds = 600
    ): string {
        $expires   = time() + $ttl_seconds;
        $signature = self::compute_signature($inscricao_id, $meta_key, $expires);

        return add_query_arg(
            [
                'action'    => self::AJAX_ACTION,
                'id'        => $inscricao_id,
                'key'       => rawurlencode($meta_key),
                'expires'   => $expires,
                'signature' => $signature,
            ],
            admin_url('admin-ajax.php')
        );
    }

    // -----------------------------------------------------------------------
    // M5.2 — Controller de download seguro
    // -----------------------------------------------------------------------

    /**
     * Handler do AJAX action `festa_download_secure`.
     * Valida assinatura → expiração → autorização → stream do arquivo.
     */
    public static function serve_file(): void
    {
        // Extrair e sanitizar parâmetros da query string
        $inscricao_id = (int) ($_GET['id'] ?? 0);
        $meta_key     = is_string($_GET['key'] ?? null) ? (string) $_GET['key'] : '';
        $expires      = (int) ($_GET['expires'] ?? 0);
        $signature    = is_string($_GET['signature'] ?? null) ? (string) $_GET['signature'] : '';

        // 1. Validação de assinatura HMAC (timing-safe)
        $expected = self::compute_signature($inscricao_id, $meta_key, $expires);
        if (! hash_equals($expected, $signature)) {
            wp_die('Link inválido.', 'Forbidden', ['response' => 403]);
        }

        // 2. Expiração
        if ($expires < time()) {
            wp_die('Este link expirou. Volte ao painel e clique novamente no documento.', 'Link expirado', ['response' => 403]);
        }

        // 3. Parâmetros mínimos válidos
        if ($inscricao_id <= 0 || $meta_key === '') {
            wp_die('', 'Bad Request', ['response' => 400]);
        }

        // 4. Gatekeeper de autorização
        self::assert_download_authorized($inscricao_id);

        // 5. Resolver caminho do arquivo (proteção contra directory traversal)
        $info = self::info($inscricao_id, $meta_key);
        if ($info === null || $info['path'] === '') {
            wp_die('Documento não encontrado.', 'Arquivo não encontrado', ['response' => 404]);
        }

        // 6. Stream seguro.
        // .html (documentos gerados) → inline: abre no navegador para visualização/impressão.
        // demais → attachment: força download.
        self::send(
            $info['path'],
            $info['mime'],
            self::download_name($inscricao_id, $meta_key, $info['ext']),
            $info['ext'] !== 'html'
        );
    }

    /**
     * Envia um arquivo ao navegador e encerra a requisição.
     * Usado pelo download assinado e pelas telas de gerenciamento.
     */
    public static function send(string $path, string $mime, string $name, bool $download): never
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        nocache_headers();
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . (int) filesize($path));
        header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="' . str_replace('"', '', $name) . '"');
        header('Cache-Control: private, no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('X-Content-Type-Options: nosniff');

        // Documentos gerados são HTML estático com estilo embutido: nada de
        // script, imagem externa ou formulário é necessário para exibi-los.
        if (str_starts_with($mime, 'text/html')) {
            header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'");
        }

        readfile($path);
        exit;
    }

    // -----------------------------------------------------------------------
    // Helpers privados
    // -----------------------------------------------------------------------

    /**
     * Computa a assinatura HMAC dos parâmetros da URL de download.
     * Concatenação com "|" previne ataques de extensão de comprimento.
     */
    private static function compute_signature(
        int    $inscricao_id,
        string $meta_key,
        int    $expires
    ): string {
        return Crypto::sign(implode('|', [$inscricao_id, $meta_key, $expires]));
    }

    /**
     * Gatekeeper de autorização para o download assinado.
     * Permite: o participante dono da inscrição (sessão silo) OU a equipe
     * (Comissão / Defesa Civil).
     *
     * @throws void — chama wp_die(403) diretamente (é um controller HTTP).
     */
    private static function assert_download_authorized(int $inscricao_id): void
    {
        if (
            current_user_can('festa_analisar_inscricao')
            || current_user_can('festa_confirmar_colchoes')
        ) {
            return;
        }

        // Participante: somente a sessão autenticada na própria inscrição
        if (AuthManager::get_current_inscricao_id() === $inscricao_id) {
            return;
        }

        wp_die(
            'Entre no painel de inscrição com o seu CPF e senha para abrir este documento.',
            'Acesso não autorizado',
            ['response' => 403]
        );
    }

    /**
     * Gera um UUID v4 criptograficamente seguro via random_bytes().
     * Formato: xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx (RFC 4122)
     */
    private static function uuid_v4(): string
    {
        $data = random_bytes(16);

        // Versão 4: bits 12-15 do time_hi_and_version = 0100
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);

        // Variante RFC 4122: bits 6-7 do clock_seq_hi = 10
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
