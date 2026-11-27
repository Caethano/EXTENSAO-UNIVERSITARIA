<?php

declare(strict_types=1);

namespace Festa\Pdf;

use Festa\Fields\Fields;
use Festa\Settings\Settings;
use Festa\Upload\Handler;
use RuntimeException;

/**
 * Orquestrador de geração de documentos HTML.
 *
 * Fluxo:
 *  1. Renderiza template PHP via output buffering → HTML puro.
 *  2. Salva o HTML em FESTA_UPLOADS_DIR/{inscricao_id}/{uuid}.html.
 *  3. Persiste o UUID no meta do post.
 *  4. Retorna o UUID filename.
 *
 * O arquivo .html é servido via URL assinada pelo Upload\Handler com
 * Content-Disposition: inline, permitindo visualização e impressão no navegador.
 * Zero dependências externas — plug and play.
 *
 * Listener do hook `festa_status_transition` registrado em boot_hooks().
 */
final class Generator
{
    private const TEMPLATES_DIR = FESTA_PLUGIN_DIR . 'includes/pdf/templates/';

    /**
     * Mapa: status de destino → template a gerar + meta onde salvar.
     * Chave: novo status. Valor: [template, meta_key].
     */
    private const STATUS_TEMPLATE_MAP = [
        // Gerado na Fase 1 (status = rascunho → aguardando_analise):
        // a ficha é gerada antes da transição, chamada diretamente pelo handler AJAX.
        // Os demais são disparados pelo hook de transição:
        'aguardando_comprovantes' => null, // nenhum PDF nesta transição
        'concluido'               => ['termo-cessao',    '_upload_termo_cessao'],
    ];

    // -----------------------------------------------------------------------
    // Bootstrap
    // -----------------------------------------------------------------------

    public static function boot_hooks(): void
    {
        // Listener do Event Bus: gera PDFs automáticos em transições específicas
        add_action('festa_status_transition', [self::class, 'on_status_transition'], 10, 4);
    }

    // -----------------------------------------------------------------------
    // API pública
    // -----------------------------------------------------------------------

    /**
     * Renderiza um template PHP em HTML e salva o resultado no disco.
     *
     * @param string $template_name  Nome do template sem extensão (ex: 'ficha-inscricao').
     * @param array<string, mixed> $data  Dados expostos como $data no template.
     * @param int    $inscricao_id   ID do CPT; define o subdiretório de destino.
     * @param string $meta_key       Meta do post onde persistir o UUID filename.
     *                               Passar string vazia para não persistir (ex: preview).
     *
     * @return string UUID filename gerado (ex: "a1b2c3d4-…-e5f6.html").
     *
     * @throws RuntimeException Se o template não existir, ou qualquer falha de I/O.
     */
    public static function generate(
        string $template_name,
        array  $data,
        int    $inscricao_id,
        string $meta_key = ''
    ): string {
        $template_path = self::TEMPLATES_DIR . $template_name . '.php';

        if (! is_file($template_path)) {
            throw new RuntimeException(
                sprintf('[Festa\Pdf] Template não encontrado: %s', $template_path)
            );
        }

        // 1. Renderizar template PHP → HTML via output buffering.
        //    $data é exposto como variável $data dentro do escopo do template.
        $html = self::render_template($template_path, $data);

        // 2. Salvar o HTML no diretório seguro como arquivo .html.
        $filename = self::save_to_disk($html, $inscricao_id);

        // 3. Persistir meta (opcional)
        if ($meta_key !== '') {
            update_post_meta($inscricao_id, $meta_key, $filename);
        }

        return $filename;
    }

    // -----------------------------------------------------------------------
    // Listener do hook festa_status_transition
    // -----------------------------------------------------------------------

    /**
     * @param int    $inscricao_id
     * @param string $status_anterior
     * @param string $novo_status
     * @param int    $user_id
     */
    public static function on_status_transition(
        int    $inscricao_id,
        string $status_anterior,
        string $novo_status,
        int    $user_id
    ): void {
        $map = self::STATUS_TEMPLATE_MAP;

        if (! isset($map[$novo_status]) || $map[$novo_status] === null) {
            return; // nenhum PDF automático para esta transição
        }

        [$template_name, $meta_key] = $map[$novo_status];

        $data = self::build_data($inscricao_id);

        try {
            self::generate($template_name, $data, $inscricao_id, $meta_key);
        } catch (RuntimeException $e) {
            // Log de falha — não interrompe o fluxo principal da transição.
            // O audit log (Módulo 10) deverá capturar este erro via hook.
            error_log($e->getMessage());
        }
    }

    // -----------------------------------------------------------------------
    // Helpers privados
    // -----------------------------------------------------------------------

    /**
     * Renderiza o template PHP em HTML puro via output buffering.
     * $data é a única variável injetada no escopo do template.
     *
     * @param array<string, mixed> $data
     */
    private static function render_template(string $template_path, array $data): string
    {
        if (ob_start() === false) {
            throw new RuntimeException('[Festa\Documento] Falha ao iniciar output buffering.');
        }

        try {
            include $template_path;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw new RuntimeException(
                sprintf('[Festa\Documento] Erro ao renderizar template: %s', $e->getMessage()),
                0,
                $e
            );
        }

        $html = ob_get_clean();

        if ($html === false || $html === '') {
            throw new RuntimeException('[Festa\Documento] Template gerou HTML vazio.');
        }

        return $html;
    }

    /**
     * Salva o conteúdo HTML no disco com nome UUID v4.
     *
     * @return string UUID filename (ex: "a1b2c3d4-…-e5f6.html").
     */
    private static function save_to_disk(string $html_content, int $inscricao_id): string
    {
        $target_dir = Handler::ensure_dir($inscricao_id);

        $filename    = wp_generate_uuid4() . '.html';
        $target_path = $target_dir . DIRECTORY_SEPARATOR . $filename;

        if (file_put_contents($target_path, $html_content) === false) {
            throw new RuntimeException(
                sprintf('[Festa\Pdf] Falha ao gravar documento em: %s', $target_path)
            );
        }

        return $filename;
    }

    /**
     * Monta o array $data completo para um inscricao_id.
     * Descriptografa o CPF e aplica máscara LGPD antes de expor ao template.
     *
     * @return array<string, mixed>
     */
    private static function build_data(int $inscricao_id): array
    {
        $cpf_enc    = (string) get_post_meta($inscricao_id, '_cpf', true);
        $cpf_masked = '';

        if ($cpf_enc !== '') {
            try {
                $cpf_raw    = \Festa\Crypto\Crypto::decrypt($cpf_enc);
                $cpf_masked = \Festa\Crypto\Crypto::mask_cpf($cpf_raw);
            } catch (\Throwable) {
                $cpf_masked = '***.***.***-**';
            }
        }

        $coccao_raw = (string) get_post_meta($inscricao_id, '_coccao', true);

        return [
            'inscricao_id'      => $inscricao_id,
            'nome_responsavel'  => (string) get_post_meta($inscricao_id, '_nome_responsavel',  true),
            'cpf_masked'        => $cpf_masked,
            'email'             => (string) get_post_meta($inscricao_id, '_email',             true),
            'telefone'          => (string) get_post_meta($inscricao_id, '_telefone',          true),
            'nome_grupo'        => (string) get_post_meta($inscricao_id, '_nome_grupo',        true),
            'qtd_participantes' => (int)    get_post_meta($inscricao_id, '_qtd_participantes', true),
            'coccao'            => Fields::coccao_label($coccao_raw),
            'espaco'            => (int)    get_post_meta($inscricao_id, '_espaco',            true),
            'numero_tenda'      => (string) get_post_meta($inscricao_id, '_numero_tenda',      true),
            'data_inscricao'    => (string) get_post_meta($inscricao_id, '_data_inscricao',    true),
            'data_evento'       => Settings::text('data_evento'),
            'data_emissao'      => wp_date('d/m/Y \à\s H:i'),
        ];
    }
}
