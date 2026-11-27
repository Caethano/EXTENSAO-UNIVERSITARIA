<?php
/**
 * Template PDF: Comprovante de Doação de Colchões
 *
 * Gerado automaticamente pela Defesa Civil ao confirmar o recebimento.
 * Variável disponível: $data (array com os campos da inscrição)
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
    line-height: 1.6;
  }
  .page { padding: 22mm 20mm; }
  .header { text-align: center; margin-bottom: 24px; }
  h1 { font-size: 13pt; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 4px; }
  .header-sub { font-size: 9.5pt; color: #555; }
  hr { border: none; border-top: 2px solid #1a1a1a; margin: 0 0 20px; }
  .recibo-body {
    font-size: 11pt;
    text-align: justify;
    line-height: 1.8;
    margin-bottom: 24px;
  }
  .recibo-body strong { color: #000; }
  .info-box {
    border: 1px solid #ccc;
    padding: 12px 16px;
    margin: 20px 0;
    background: #fafafa;
  }
  .info-box table { width: 100%; border-collapse: collapse; }
  .info-box td { padding: 4px 0; font-size: 10.5pt; }
  .info-box td.label { width: 42%; color: #555; font-weight: bold; }
  .stamp {
    text-align: center;
    margin-top: 32px;
    padding-top: 14px;
    border-top: 1px solid #ccc;
    font-size: 9.5pt;
    color: #444;
  }
  .stamp-line {
    border-top: 1px solid #333;
    width: 220px;
    margin: 30px auto 6px;
  }
  .footer {
    margin-top: 28px;
    font-size: 8.5pt;
    color: #999;
    text-align: center;
    border-top: 1px solid #eee;
    padding-top: 8px;
  }
</style>
</head>
<body>
<div class="page">

  <div class="header">
    <h1>Comprovante de Doação de Colchões</h1>
    <p class="header-sub">Secretaria Municipal de Defesa Civil &nbsp;|&nbsp; Festa dos Amigos 2026</p>
  </div>
  <hr>

  <div class="recibo-body">
    <p>
      A Secretaria Municipal de Defesa Civil do Município certifica que o(a) responsável
      <strong><?php echo esc_html($data['nome_responsavel'] ?? ''); ?></strong>,
      inscrito(a) sob o CPF <strong><?php echo esc_html($data['cpf_masked'] ?? ''); ?></strong>,
      representante do grupo "<strong><?php echo esc_html($data['nome_grupo'] ?? ''); ?></strong>",
      realizou a doação de colchões em conformidade com as exigências do
      Edital 001/2026/SMDES para participação no evento Festa dos Amigos 2026.
    </p>
    <p>
      A entrega foi recebida e conferida por agente da Defesa Civil em
      <strong><?php echo esc_html($data['data_emissao'] ?? ''); ?></strong>,
      ficando o doador isento de qualquer pendência referente a este requisito.
    </p>
  </div>

  <div class="info-box">
    <table>
      <tr>
        <td class="label">Responsável</td>
        <td><?php echo esc_html($data['nome_responsavel'] ?? ''); ?></td>
      </tr>
      <tr>
        <td class="label">CPF</td>
        <td><?php echo esc_html($data['cpf_masked'] ?? ''); ?></td>
      </tr>
      <tr>
        <td class="label">Grupo</td>
        <td><?php echo esc_html($data['nome_grupo'] ?? ''); ?></td>
      </tr>
      <tr>
        <td class="label">Protocolo de Inscrição</td>
        <td>#<?php echo esc_html((string) ($data['inscricao_id'] ?? '')); ?></td>
      </tr>
      <tr>
        <td class="label">Data e Hora da Confirmação</td>
        <td><?php echo esc_html($data['data_emissao'] ?? ''); ?></td>
      </tr>
    </table>
  </div>

  <div class="stamp">
    <div class="stamp-line"></div>
    Assinatura e Carimbo — Agente da Defesa Civil
    <br>Secretaria Municipal de Defesa Civil
  </div>

  <p class="footer">
    Documento gerado automaticamente pelo sistema &nbsp;|&nbsp;
    Festa dos Amigos 2026 — SMDES &nbsp;|&nbsp;
    Protocolo #<?php echo esc_html((string) ($data['inscricao_id'] ?? '')); ?>
  </p>

</div>
</body>
</html>
