<?php

declare(strict_types=1);

namespace Festa\Fields;

/**
 * Definição única das opções, dos documentos e dos validadores da inscrição.
 *
 * Tudo o que depende de "quais documentos existem e quem envia cada um"
 * (upload do participante, painel de gerenciamento, ZIP, CSV, expurgo LGPD)
 * consulta esta classe, para que a regra fique escrita num só lugar.
 */
final class Fields
{
    // -----------------------------------------------------------------------
    // Opções de escolha
    // -----------------------------------------------------------------------

    /** @return array<string, string> */
    public static function coccao(): array
    {
        return [
            'gas'    => 'Gás',
            'carvao' => 'Carvão',
        ];
    }

    /** @return array<int, string> */
    public static function espaco(): array
    {
        return [
            1 => '1 tenda',
            2 => '2 tendas',
        ];
    }

    public static function coccao_label(string $value): string
    {
        return self::coccao()[$value] ?? '';
    }

    public static function espaco_label(int|string $value): string
    {
        return self::espaco()[(int) $value] ?? '';
    }

    // -----------------------------------------------------------------------
    // Documentos
    // -----------------------------------------------------------------------

    /**
     * Todos os documentos de uma inscrição, na ordem em que surgem no fluxo.
     *
     * meta:   meta do post que guarda o nome do arquivo no diretório privado.
     * origem: participante | comissao | sistema (gerado automaticamente).
     * accept: doc (imagem ou PDF) | image | pdf — só para os enviados por alguém.
     *
     * @return array<string, array{label: string, meta: string, origem: string, accept: string}>
     */
    public static function documents(): array
    {
        return [
            'identidade' => [
                'label'  => 'Documento de identidade',
                'meta'   => '_upload_identidade',
                'origem' => 'participante',
                'accept' => 'doc',
            ],
            'comprovante_residencia' => [
                'label'  => 'Comprovante de residência',
                'meta'   => '_upload_comprovante_residencia',
                'origem' => 'participante',
                'accept' => 'doc',
            ],
            'ficha_gerada' => [
                'label'  => 'Ficha de inscrição (gerada pelo sistema)',
                'meta'   => '_upload_ficha_gerada',
                'origem' => 'sistema',
                'accept' => '',
            ],
            'ficha_assinada' => [
                'label'  => 'Ficha de inscrição assinada',
                'meta'   => '_upload_ficha_assinada',
                'origem' => 'participante',
                'accept' => 'doc',
            ],
            'deferimento' => [
                'label'  => 'Deferimento da Comissão',
                'meta'   => '_upload_deferimento',
                'origem' => 'comissao',
                'accept' => 'doc',
            ],
            'recibo_tenda' => [
                'label'  => 'Recibo da tenda',
                'meta'   => '_upload_recibo_tenda',
                'origem' => 'participante',
                'accept' => 'doc',
            ],
            'comprovante_doacao' => [
                'label'  => 'Comprovante de doação de colchões',
                'meta'   => '_upload_comprovante_doacao',
                'origem' => 'sistema',
                'accept' => '',
            ],
            'termo_cessao' => [
                'label'  => 'Termo de Cessão de Espaço',
                'meta'   => '_upload_termo_cessao',
                'origem' => 'sistema',
                'accept' => '',
            ],
        ];
    }

    /** @return array{label: string, meta: string, origem: string, accept: string}|null */
    public static function document(string $key): ?array
    {
        return self::documents()[$key] ?? null;
    }

    public static function document_label(string $key): string
    {
        return self::documents()[$key]['label'] ?? $key;
    }

    /** Chave do documento a partir do meta (ex.: '_upload_recibo_tenda' → 'recibo_tenda'). */
    public static function document_key(string $meta): string
    {
        foreach (self::documents() as $key => $doc) {
            if ($doc['meta'] === $meta) {
                return $key;
            }
        }

        return '';
    }

    /**
     * Metas de todos os documentos (usado pelo expurgo LGPD).
     *
     * @return list<string>
     */
    public static function upload_meta_keys(): array
    {
        return array_values(array_column(self::documents(), 'meta'));
    }

    // -----------------------------------------------------------------------
    // Aceite (edital + LGPD)
    // -----------------------------------------------------------------------

    /** Texto do aceite exibido no fim do formulário de inscrição. */
    public static function aceite_texto(): string
    {
        return 'Declaro que li e concordo com o Edital 001/2026/SMDES e com os termos de '
            . 'responsabilidade do evento, e autorizo o tratamento dos dados pessoais e dos '
            . 'documentos enviados nesta inscrição, exclusivamente para a análise da inscrição '
            . 'e a participação na Festa dos Amigos 2026, nos termos da Lei Geral de Proteção '
            . 'de Dados (Lei nº 13.709/2018 – LGPD).';
    }

    // -----------------------------------------------------------------------
    // Validadores
    // -----------------------------------------------------------------------

    public static function digits(string $value): string
    {
        return (string) preg_replace('/\D/', '', $value);
    }

    /** CPF com 11 dígitos e dígitos verificadores corretos. */
    public static function valid_cpf(string $cpf): bool
    {
        $cpf = self::digits($cpf);

        if (strlen($cpf) !== 11 || preg_match('/^(\d)\1{10}$/', $cpf)) {
            return false;
        }

        for ($t = 9; $t < 11; $t++) {
            $sum = 0;
            for ($i = 0; $i < $t; $i++) {
                $sum += (int) $cpf[$i] * ($t + 1 - $i);
            }
            if ((int) $cpf[$t] !== (10 * $sum) % 11 % 10) {
                return false;
            }
        }

        return true;
    }

    /** Telefone com DDD: 10 (fixo) ou 11 (celular) dígitos. */
    public static function valid_phone(string $phone): bool
    {
        $len = strlen(self::digits($phone));

        return $len >= 10 && $len <= 11;
    }

    /** "(47) 99999-0000" a partir de qualquer formato digitado. */
    public static function format_phone(string $phone): string
    {
        $d = self::digits($phone);

        if (strlen($d) === 11) {
            return sprintf('(%s) %s-%s', substr($d, 0, 2), substr($d, 2, 5), substr($d, 7));
        }
        if (strlen($d) === 10) {
            return sprintf('(%s) %s-%s', substr($d, 0, 2), substr($d, 2, 4), substr($d, 6));
        }

        return $phone;
    }
}
