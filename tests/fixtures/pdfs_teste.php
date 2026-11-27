<?php
/**
 * Gera os PDFs de teste dos cadastros (tests/popular_banco.py) com o mesmo
 * gerador de PDF do plugin (Festa\Pdf\Writer).
 *
 * Entrada: $fp_in, lista de {chave, tipo, dados} montada pelo Python e
 * colocada no início deste script. Saída: JSON {chave: PDF em base64}.
 *
 * Tipos: identidade, comprovante_residencia, ficha_assinada, deferimento,
 * recibo_tenda. Todos levam o aviso "documento de teste, sem valor legal".
 */

use Festa\Crypto\Crypto;
use Festa\Fields\Fields;
use Festa\Pdf\Writer;

function fp_cabecalho(Writer $pdf, string $titulo, string $subtitulo = ''): void
{
    $pdf->line('DOCUMENTO DE TESTE — gerado automaticamente, sem valor legal', 8, true, 0, 0.55);
    $pdf->spacer(6);
    $pdf->line($titulo, 16, true);
    if ($subtitulo !== '') {
        $pdf->line($subtitulo, 10, false, 0, 0.35);
    }
    $pdf->rule();
    $pdf->spacer(8);
}

function fp_paragrafo(Writer $pdf, string $texto, int $largura = 92): void
{
    foreach (Writer::wrap($texto, $largura) as $linha) {
        $pdf->line($linha, 10.5);
    }
    $pdf->spacer(6);
}

function fp_assinatura(Writer $pdf, string $nome, string $papel, string $data): void
{
    $pdf->spacer(36);
    $pdf->line('_______________________________________________', 10);
    $pdf->line($nome, 10, true);
    $pdf->line($papel, 9, false, 0, 0.35);
    $pdf->spacer(4);
    $pdf->line('Assinado eletronicamente em ' . $data . ' (assinatura de teste).', 8, false, 0, 0.45);
}

function fp_cpf(string $cpf): string
{
    return substr($cpf, 0, 3) . '.' . substr($cpf, 3, 3) . '.' . substr($cpf, 6, 3) . '-' . substr($cpf, 9, 2);
}

function fp_reais(int $valor): string
{
    return 'R$ ' . number_format($valor, 2, ',', '.');
}

function fp_documento(string $tipo, array $d): string
{
    $pdf  = new Writer();
    $hoje = wp_date('d/m/Y');

    switch ($tipo) {
        case 'identidade':
            fp_cabecalho($pdf, 'Carteira de Identidade', 'República Federativa do Brasil — modelo fictício');
            $pdf->table_row('Nome', $d['nome']);
            $pdf->table_row('CPF', fp_cpf($d['cpf']));
            $pdf->table_row('Registro Geral (RG)', $d['rg']);
            $pdf->table_row('Data de nascimento', $d['nascimento']);
            $pdf->table_row('Naturalidade', 'Cidade Exemplo — SC');
            $pdf->table_row('Órgão expedidor', 'SSP/SC');
            $pdf->table_row('Data de expedição', '15/03/2019');
            break;

        case 'comprovante_residencia':
            fp_cabecalho($pdf, 'Conta de Energia Elétrica', 'Companhia Exemplo de Energia S.A. — empresa fictícia');
            $pdf->table_row('Titular', $d['nome']);
            $pdf->table_row('Endereço', $d['endereco']);
            $pdf->table_row('Unidade consumidora', $d['uc']);
            $pdf->table_row('Mês de referência', 'Setembro/2026');
            $pdf->table_row('Vencimento', '10/10/2026');
            $pdf->table_row('Consumo', '213 kWh');
            $pdf->table_row('Valor', 'R$ 187,42');
            break;

        case 'ficha_assinada':
            fp_cabecalho($pdf, 'Ficha de Inscrição — Festa dos Amigos 2026', 'Protocolo #' . $d['id'] . ' — via assinada pelo responsável');
            $pdf->table_row('Responsável', $d['nome']);
            $pdf->table_row('CPF', Crypto::mask_cpf($d['cpf']));
            $pdf->table_row('E-mail', $d['email']);
            $pdf->table_row('Telefone', $d['telefone']);
            $pdf->table_row('Grupo', $d['grupo']);
            $pdf->table_row('Participantes', (string) $d['qtd']);
            $pdf->table_row('Cocção', Fields::coccao_label($d['coccao']));
            $pdf->table_row('Espaço', Fields::espaco_label($d['espaco']));
            $pdf->spacer(12);
            $pdf->line('Termo de aceite', 11, true);
            fp_paragrafo($pdf, Fields::aceite_texto());
            fp_assinatura($pdf, $d['nome'], 'Responsável pelo grupo', $hoje);
            break;

        case 'deferimento':
            fp_cabecalho($pdf, 'Deferimento da Comissão', 'Festa dos Amigos 2026 — Protocolo #' . $d['id']);
            $pdf->table_row('Responsável', $d['nome']);
            $pdf->table_row('CPF', Crypto::mask_cpf($d['cpf']));
            $pdf->table_row('Grupo', $d['grupo']);
            $pdf->table_row('Participantes', (string) $d['qtd']);
            $pdf->table_row('Espaço', Fields::espaco_label($d['espaco']));
            $pdf->table_row('Decisão', 'DEFERIDO');
            $pdf->spacer(12);
            fp_paragrafo($pdf, 'A Comissão Organizadora conferiu a ficha de inscrição assinada e os documentos '
                . 'apresentados e verificou que estão de acordo com o regulamento do evento. A inscrição '
                . 'está deferida e segue para o sorteio das tendas.');
            fp_assinatura($pdf, 'Comissão Organizadora', 'Festa dos Amigos 2026', $hoje);
            break;

        case 'recibo_tenda':
            $valor = 350 * max(1, (int) $d['espaco']);
            fp_cabecalho($pdf, 'Recibo de Locação de Tenda', 'Tendas Exemplo Ltda. — CNPJ 00.000.000/0000-00 — empresa fictícia');
            $pdf->table_row('Recibo nº', sprintf('%04d/2026', (int) $d['id']));
            $pdf->table_row('Recebemos de', $d['nome']);
            $pdf->table_row('CPF', Crypto::mask_cpf($d['cpf']));
            $pdf->table_row('Valor', fp_reais($valor));
            $pdf->table_row('Referente a', sprintf(
                'Locação de %d tenda(s) de 3 x 3 m para a Festa dos Amigos 2026 — tenda nº %s',
                (int) $d['espaco'],
                $d['tenda']
            ));
            $pdf->table_row('Forma de pagamento', 'PIX');
            $pdf->table_row('Data', $hoje);
            fp_assinatura($pdf, 'Tendas Exemplo Ltda.', 'Locadora', $hoje);
            break;

        default:
            WP_CLI::error('tipo de documento desconhecido: ' . $tipo);
    }

    return $pdf->output();
}

$fp_saida = [];
foreach ($fp_in as $fp_item) {
    $fp_saida[$fp_item['chave']] = base64_encode(fp_documento($fp_item['tipo'], $fp_item['dados']));
}
echo "\n@@FESTA_JSON@@" . wp_json_encode($fp_saida) . "\n";
