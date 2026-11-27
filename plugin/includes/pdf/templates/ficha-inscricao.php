<?php
/**
 * Template PDF: Ficha de Inscrição + Termo de Responsabilidade
 *
 * Variável disponível: $data (array com os campos da inscrição)
 * Renderizado via output buffering em Generator::render_template().
 * Todo output é escapado com esc_html() por política LGPD/XSS.
 */
declare(strict_types=1);

/** @var array<string, mixed> $data */
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body {
    font-family: Helvetica, Arial, sans-serif;
    font-size: 11pt;
    color: #1a1a1a;
    line-height: 1.5;
  }
  .page { padding: 20mm 18mm; }
  h1 {
    font-size: 14pt;
    text-align: center;
    text-transform: uppercase;
    letter-spacing: 1px;
    margin-bottom: 2px;
  }
  .subtitle {
    font-size: 9pt;
    text-align: center;
    color: #555;
    margin-bottom: 16px;
  }
  hr { border: none; border-top: 1px solid #ccc; margin: 12px 0; }
  .section-title {
    font-size: 10pt;
    font-weight: bold;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #333;
    margin: 14px 0 6px;
    border-bottom: 1px solid #ddd;
    padding-bottom: 3px;
  }
  table.fields {
    width: 100%;
    border-collapse: collapse;
  }
  table.fields td {
    padding: 5px 6px;
    vertical-align: top;
    font-size: 10.5pt;
  }
  table.fields td.label {
    width: 38%;
    color: #555;
    font-weight: bold;
  }
  table.fields tr:nth-child(even) td { background: #f7f7f7; }
  .termo {
    margin-top: 18px;
    font-size: 10pt;
    text-align: justify;
    line-height: 1.6;
  }
  .termo p { margin-bottom: 8px; }
  .signatures {
    margin-top: 30px;
    width: 100%;
    border-collapse: collapse;
  }
  .signatures td {
    width: 50%;
    padding: 0 10px;
    vertical-align: bottom;
    text-align: center;
    font-size: 9.5pt;
  }
  .sig-line {
    border-top: 1px solid #333;
    padding-top: 4px;
    margin-top: 32px;
  }
  .watermark-pending {
    font-size: 9pt;
    color: #999;
    text-align: center;
    margin-top: 6px;
    font-style: italic;
  }
  .footer {
    margin-top: 20px;
    font-size: 8.5pt;
    color: #888;
    text-align: center;
    border-top: 1px solid #eee;
    padding-top: 8px;
  }
</style>
</head>
<body>
<div class="page">

  <!-- Cabeçalho -->
  <h1>Festa dos Amigos 2026</h1>
  <p class="subtitle">Secretaria Municipal de Desenvolvimento Social — Ficha de Inscrição</p>
  <hr>

  <!-- Seção 1: Dados do Responsável -->
  <p class="section-title">1. Dados do Responsável</p>
  <table class="fields">
    <tr>
      <td class="label">Nome</td>
      <td><?php echo esc_html($data['nome_responsavel'] ?? ''); ?></td>
    </tr>
    <tr>
      <td class="label">CPF</td>
      <td><?php echo esc_html($data['cpf_masked'] ?? ''); ?></td>
    </tr>
    <tr>
      <td class="label">E-mail</td>
      <td><?php echo esc_html($data['email'] ?? ''); ?></td>
    </tr>
    <tr>
      <td class="label">Telefone</td>
      <td><?php echo esc_html($data['telefone'] ?? ''); ?></td>
    </tr>
  </table>

  <!-- Seção 2: Dados do Grupo -->
  <p class="section-title">2. Dados do Grupo</p>
  <table class="fields">
    <tr>
      <td class="label">Nome do Grupo</td>
      <td><?php echo esc_html($data['nome_grupo'] ?? ''); ?></td>
    </tr>
    <tr>
      <td class="label">Nº de Participantes</td>
      <td><?php echo esc_html((string) ($data['qtd_participantes'] ?? '')); ?></td>
    </tr>
    <tr>
      <td class="label">Tipo de Cocção</td>
      <td><?php echo esc_html($data['coccao'] ?? ''); ?></td>
    </tr>
    <tr>
      <td class="label">Espaço Solicitado</td>
      <td><?php echo esc_html((string) ($data['espaco'] ?? '')); ?> tenda(s)</td>
    </tr>
    <tr>
      <td class="label">Data de Inscrição</td>
      <td><?php echo esc_html($data['data_inscricao'] ?? ''); ?></td>
    </tr>
  </table>

  <!-- Seção 3: Termo de Responsabilidade -->
  <p class="section-title">3. Termo de Responsabilidade — Edital 001/2026/SMDES</p>
  <div class="termo">
    <p>
      Eu, <strong><?php echo esc_html($data['nome_responsavel'] ?? ''); ?></strong>,
      inscrito(a) sob o CPF <strong><?php echo esc_html($data['cpf_masked'] ?? ''); ?></strong>,
      na qualidade de responsável pelo grupo
      "<strong><?php echo esc_html($data['nome_grupo'] ?? ''); ?></strong>",
      ao assinar o presente documento declaro ciência e concordância integral com as
      normas do Edital 001/2026/SMDES e com as regras de uso do espaço público concedido
      para realização do evento Festa dos Amigos 2026, conforme os termos abaixo:
    </p>
    <p>
      <strong>I.</strong> Responsabilizo-me pela conduta de todos os integrantes do grupo
      durante o período de montagem, realização e desmontagem do evento, zelando pela
      ordem pública, segurança e respeito ao patrimônio.
    </p>
    <p>
      <strong>II.</strong> Comprometo-me a realizar a doação mínima estipulada no edital
      de colchões em bom estado de conservação à Secretaria Municipal de Defesa Civil,
      como condição necessária para a efetivação da inscrição.
    </p>
    <p>
      <strong>III.</strong> Declaro estar ciente de que o descumprimento de qualquer
      cláusula deste termo implicará no cancelamento imediato da inscrição, sem direito
      a recurso, bem como possível impedimento de participação em edições futuras.
    </p>
    <p>
      <strong>IV.</strong> Autorizo o uso de imagem do grupo para fins de divulgação
      institucional do evento, sem qualquer ônus para a administração municipal.
    </p>
  </div>

  <!-- Assinaturas -->
  <table class="signatures">
    <tr>
      <td>
        <div class="sig-line">
          Assinatura do Responsável pelo Grupo
          <div class="watermark-pending"><?php echo esc_html($data['nome_responsavel'] ?? ''); ?></div>
        </div>
      </td>
      <td>
        <div class="sig-line">
          Deferido / Indeferido pela Comissão
          <div class="watermark-pending">Aguardando análise</div>
        </div>
      </td>
    </tr>
  </table>

  <!-- Rodapé -->
  <p class="footer">
    Emitido em <?php echo esc_html($data['data_emissao'] ?? ''); ?>
    &nbsp;|&nbsp; Protocolo #<?php echo esc_html((string) ($data['inscricao_id'] ?? '')); ?>
    &nbsp;|&nbsp; Festa dos Amigos 2026 — SMDES
  </p>

</div>
</body>
</html>
