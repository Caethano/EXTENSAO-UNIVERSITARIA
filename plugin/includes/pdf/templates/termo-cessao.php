<?php
/**
 * Template PDF: Termo de Cessão de Espaço
 *
 * Gerado automaticamente pelo sistema ao atingir status `concluido`.
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
    line-height: 1.7;
  }
  .page { padding: 22mm 20mm; }
  .header { text-align: center; margin-bottom: 6px; }
  h1 { font-size: 14pt; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 4px; }
  h2 { font-size: 11pt; text-transform: uppercase; letter-spacing: 0.5px; color: #333; margin-bottom: 16px; }
  hr.thick { border: none; border-top: 2px solid #1a1a1a; margin: 0 0 18px; }
  .numero {
    text-align: center;
    font-size: 10pt;
    margin-bottom: 18px;
    color: #555;
  }
  .corpo {
    font-size: 11pt;
    text-align: justify;
    margin-bottom: 14px;
  }
  .clausulas { margin: 16px 0; }
  .clausula {
    margin-bottom: 10px;
    font-size: 10.5pt;
    text-align: justify;
  }
  .clausula strong { font-size: 10.5pt; }
  .destaque {
    border: 1px solid #333;
    padding: 10px 14px;
    margin: 18px 0;
    text-align: center;
    font-size: 13pt;
    font-weight: bold;
    letter-spacing: 2px;
  }
  .signatures {
    width: 100%;
    border-collapse: collapse;
    margin-top: 40px;
  }
  .signatures td {
    width: 50%;
    padding: 0 12px;
    text-align: center;
    vertical-align: bottom;
    font-size: 9.5pt;
  }
  .sig-line {
    border-top: 1px solid #333;
    padding-top: 5px;
    margin-top: 36px;
  }
  .local-data {
    text-align: center;
    margin-top: 28px;
    font-size: 10pt;
    color: #444;
  }
  .footer {
    margin-top: 22px;
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
    <h1>Prefeitura Municipal</h1>
    <h2>Secretaria Municipal de Desenvolvimento Social</h2>
  </div>
  <hr class="thick">

  <div class="numero">
    TERMO DE CESSÃO DE ESPAÇO — Nº <?php echo esc_html((string) ($data['inscricao_id'] ?? '')); ?>/2026
  </div>

  <p class="corpo">
    Pelo presente instrumento, a <strong>Secretaria Municipal de Desenvolvimento Social</strong>,
    no uso de suas atribuições legais e em conformidade com o Edital 001/2026/SMDES,
    cede, em caráter temporário e precário, o espaço público identificado abaixo, ao
    grupo inscrito sob as condições estabelecidas neste termo.
  </p>

  <div class="destaque">
    TENDA Nº <?php echo esc_html($data['numero_tenda'] ?? '—'); ?>
  </div>

  <div class="clausulas">

    <div class="clausula">
      <strong>Cláusula 1ª — Das Partes:</strong> A cedente é o Município, representado
      pela Secretaria Municipal de Desenvolvimento Social. O cessionário é o grupo
      "<strong><?php echo esc_html($data['nome_grupo'] ?? ''); ?></strong>", neste ato
      representado por <strong><?php echo esc_html($data['nome_responsavel'] ?? ''); ?></strong>,
      CPF <strong><?php echo esc_html($data['cpf_masked'] ?? ''); ?></strong>.
    </div>

    <div class="clausula">
      <strong>Cláusula 2ª — Do Objeto:</strong> A cessão refere-se ao uso temporário
      do espaço correspondente à
      <strong>Tenda Nº <?php echo esc_html($data['numero_tenda'] ?? '—'); ?></strong>,
      destinado à participação no evento <strong>Festa dos Amigos 2026</strong>,
      a ser realizado no dia <strong><?php echo esc_html($data['data_evento'] ?? '11 de Abril de 2026'); ?></strong>.
    </div>

    <div class="clausula">
      <strong>Cláusula 3ª — Das Condições:</strong> A presente cessão é gratuita,
      temporária e intransferível, sendo vedado ao cessionário ceder, sublocar ou
      de qualquer forma transferir o espaço a terceiros, sob pena de cancelamento
      imediato e responsabilização nos termos da legislação vigente.
    </div>

    <div class="clausula">
      <strong>Cláusula 4ª — Das Obrigações:</strong> O cessionário obriga-se a manter
      o espaço em perfeito estado de conservação e limpeza, respeitando as normas de
      convivência e segurança estabelecidas pela organização do evento, bem como as
      diretrizes da Defesa Civil Municipal.
    </div>

    <div class="clausula">
      <strong>Cláusula 5ª — Da Vigência:</strong> O presente termo entra em vigor na
      data de sua emissão e vigorará exclusivamente durante o período de montagem,
      realização e desmontagem do evento, conforme cronograma oficial divulgado pela
      Secretaria Municipal de Desenvolvimento Social.
    </div>

    <div class="clausula">
      <strong>Cláusula 6ª — Da Rescisão:</strong> O descumprimento de qualquer
      cláusula deste instrumento autoriza a cedente a rescindir a cessão de forma
      unilateral e imediata, sem direito a qualquer indenização ou recurso.
    </div>

  </div>

  <p class="local-data">
    Local, <?php echo esc_html($data['data_emissao'] ?? ''); ?>
  </p>

  <table class="signatures">
    <tr>
      <td>
        <div class="sig-line">
          <?php echo esc_html($data['nome_responsavel'] ?? ''); ?>
          <br>CPF: <?php echo esc_html($data['cpf_masked'] ?? ''); ?>
          <br>Responsável pelo Grupo Cessionário
        </div>
      </td>
      <td>
        <div class="sig-line">
          Secretaria Municipal de Desenvolvimento Social
          <br>Comissão Organizadora — Festa dos Amigos 2026
        </div>
      </td>
    </tr>
  </table>

  <p class="footer">
    Documento gerado automaticamente pelo sistema &nbsp;|&nbsp;
    Protocolo #<?php echo esc_html((string) ($data['inscricao_id'] ?? '')); ?>
    &nbsp;|&nbsp; Festa dos Amigos 2026 — SMDES
  </p>

</div>
</body>
</html>
