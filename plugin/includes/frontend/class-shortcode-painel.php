<?php

declare(strict_types=1);

namespace Festa\Frontend;

use Festa\Pages\Pages;
use Festa\Settings\Settings;
use Festa\Status\StateMachine;
use Festa\Status\Vagas;
use Festa\Upload\Handler;

/**
 * Shortcode [festa_painel]
 *
 * Roteamento baseado no estado de autenticação silo:
 *
 *   A) Não autenticado  → Formulário de Login (CPF + Senha).
 *   B) Autenticado      → UI da State Machine + botão de Logout.
 *                          Se status == rascunho: aviso para finalizar inscrição.
 *                          Lista de espera: posição na fila.
 *                          Indeferido: justificativa da Comissão, sem envios.
 *
 * Só a inscrição da sessão aparece aqui: o painel nunca recebe um id de fora.
 */
final class ShortcodePainel
{
    // -----------------------------------------------------------------------
    // Bootstrap
    // -----------------------------------------------------------------------

    public static function boot_hooks(): void
    {
        add_shortcode('festa_painel', [self::class, 'render']);
    }

    // -----------------------------------------------------------------------
    // Render principal
    // -----------------------------------------------------------------------

    public static function render(): string
    {
        $html         = Ui::open();
        $inscricao_id = AuthManager::get_current_inscricao_id();

        // --- Rota A: não autenticado ---
        if ($inscricao_id === null) {
            return $html . AuthManager::render_login_form() . Ui::close();
        }

        // --- Rota B: autenticado ---
        $status     = StateMachine::current($inscricao_id);
        $nome_grupo = (string) get_post_meta($inscricao_id, '_nome_grupo', true);

        ob_start();
        ?>
        <div class="festa-card">

          <!-- Cabeçalho com badge de status e logout -->
          <div class="festa-painel-head">
            <div>
              <h2>Minha inscrição</h2>
              <p class="festa-painel-meta">
                <?php if ($nome_grupo !== '') : ?>
                  Grupo: <strong><?php echo esc_html($nome_grupo); ?></strong> ·
                <?php endif; ?>
                Protocolo <strong>#<?php echo (int) $inscricao_id; ?></strong>
              </p>
            </div>
            <div class="festa-painel-tools">
              <?php
              echo Ui::status_badge($status);
              echo Ui::form_open('festa_auth_logout') . Ui::form_close('Sair', 'festa-btn festa-btn--ghost');
              ?>
            </div>
          </div>

          <?php
          echo Ui::stepper($status);
          self::render_status_block($inscricao_id, $status);
          ?>

        </div>

        <details class="festa-details">
          <summary>Trocar a senha de acesso</summary>
          <?php
          echo Ui::form_open('festa_auth_trocar_senha', '#festa-senha-ok');
          echo Ui::field_text('senha_atual', 'Senha atual', ['type' => 'password', 'attrs' => 'autocomplete="current-password"']);
          echo Ui::field_text('senha_nova', 'Nova senha', [
              'type'  => 'password',
              'help'  => 'Pelo menos ' . AuthManager::MIN_PASSWORD . ' caracteres. Uma frase só sua é mais segura e mais fácil de lembrar.',
              'attrs' => 'autocomplete="new-password" data-min="' . AuthManager::MIN_PASSWORD . '"',
          ]);
          echo Ui::field_text('senha_nova2', 'Repita a nova senha', [
              'type'  => 'password',
              'attrs' => 'autocomplete="new-password" data-match="senha_nova"',
          ]);
          echo Ui::form_close('Salvar nova senha');
          ?>
          <p class="festa-notice festa-notice--success" id="festa-senha-ok" role="status" hidden>
            &#10003; Senha alterada. Use a nova senha no próximo acesso.
          </p>
        </details>
        <?php
        return $html . (string) ob_get_clean() . Ui::close();
    }

    // -----------------------------------------------------------------------
    // Peças reutilizadas pelos blocos
    // -----------------------------------------------------------------------

    /** Link para um documento gerado pelo sistema ('' se ele não existir). */
    private static function doc_link(int $post_id, string $meta_key, string $title, string $hint, int $ttl = 3600): string
    {
        if (Handler::path($post_id, $meta_key) === '') {
            return '';
        }

        return '<a class="festa-doc-link" target="_blank" rel="noopener" href="'
            . esc_url(Handler::generate_signed_url($post_id, $meta_key, $ttl)) . '">'
            . '<span class="festa-doc-ico" aria-hidden="true">&#8615;</span>'
            . '<span><strong>' . esc_html($title) . '</strong><small>' . esc_html($hint) . '</small></span>'
            . '</a>';
    }

    /** Formulário de envio de um documento do participante. */
    private static function upload_form(int $post_id, string $doc_type, string $label, string $button): string
    {
        return Ui::form_open('festa_upload_documentos', 'reload', true)
            . '<input type="hidden" name="document_type" value="' . esc_attr($doc_type) . '">'
            . '<input type="hidden" name="inscricao_id" value="' . (int) $post_id . '">'
            . Ui::field_file('documento', $label)
            . Ui::form_close($button);
    }

    /** "Enviado em 12/03/2026 às 14:05" de um documento já recebido. */
    private static function sent_when(int $post_id, string $meta_key): string
    {
        $info = Handler::info($post_id, $meta_key);

        return $info && $info['uploaded_at'] !== ''
            ? ' em ' . mysql2date('d/m/Y \à\s H:i', $info['uploaded_at'])
            : '';
    }

    // -----------------------------------------------------------------------
    // Bloco de UI por status
    // -----------------------------------------------------------------------

    private static function render_status_block(int $post_id, string $status): void
    {
        switch ($status) {

            // ------------------------------------------------------------------
            // RASCUNHO: inscrição não finalizada — direciona para [festa_inscricao]
            // ------------------------------------------------------------------
            case StateMachine::STATUS_RASCUNHO:
                ?>
                <div class="festa-step-block">
                  <h3 class="festa-step-title">Complete sua inscrição</h3>
                  <p class="festa-text">Seu pré-cadastro foi criado, mas a inscrição ainda não foi finalizada.
                  Preencha os dados restantes e envie seus documentos.</p>
                  <a href="<?php echo esc_url(Pages::url('inscricao')); ?>" class="festa-btn">
                    Finalizar inscrição &rarr;
                  </a>
                </div>
                <?php
                break;

            // ------------------------------------------------------------------
            // AGUARDANDO ANÁLISE: comissão está revisando
            // ------------------------------------------------------------------
            case StateMachine::STATUS_ANALISE:
                $ficha_env = Handler::path($post_id, '_upload_ficha_assinada') !== '';
                ?>
                <div class="festa-step-block">
                  <h3 class="festa-step-title">Aguardando análise da Comissão</h3>
                  <p class="festa-text">Sua inscrição foi recebida e está em análise pela Comissão Organizadora.
                  Você será notificado por e-mail quando houver uma atualização.</p>
                  <div class="festa-info-box">
                    <strong>O que acontece agora?</strong><br>
                    A Comissão verificará seus documentos e registrará o deferimento.
                    Este processo pode levar alguns dias úteis.
                  </div>

                  <div class="festa-sub-block">
                    <h4>Ficha de inscrição</h4>
                    <p class="festa-text">Abra a ficha, imprima, assine (à mão ou digitalmente) e envie por aqui.
                    Pode ser uma foto nítida ou um PDF.</p>
                    <?php
                    echo self::doc_link(
                        $post_id,
                        '_upload_ficha_gerada',
                        'Abrir a ficha de inscrição',
                        'Ficha e termo de responsabilidade, prontos para imprimir'
                    );

                    if ($ficha_env) {
                        echo '<p class="festa-notice festa-notice--success">&#10003; Ficha assinada enviada'
                            . esc_html(self::sent_when($post_id, '_upload_ficha_assinada')) . '.</p>';
                        echo '<details class="festa-details"><summary>Enviar outra versão da ficha</summary>'
                            . self::upload_form($post_id, 'ficha_assinada', 'Ficha de inscrição assinada', 'Substituir ficha')
                            . '</details>';
                    } else {
                        echo self::upload_form($post_id, 'ficha_assinada', 'Ficha de inscrição assinada', 'Enviar ficha assinada');
                    }
                    ?>
                  </div>
                </div>
                <?php
                break;

            // ------------------------------------------------------------------
            // LISTA DE ESPERA: vagas esgotadas; aguarda ser chamado pela Comissão
            // ------------------------------------------------------------------
            case StateMachine::STATUS_LISTA_ESPERA:
                $posicao   = Vagas::posicao($post_id);
                $ficha_env = Handler::path($post_id, '_upload_ficha_assinada') !== '';
                ?>
                <div class="festa-step-block">
                  <h3 class="festa-step-title">Você está na lista de espera</h3>
                  <div class="festa-fila-destaque">
                    Sua posição na fila <span><?php echo $posicao > 0 ? (int) $posicao . 'º' : '—'; ?></span>
                  </div>
                  <p class="festa-text">Sua inscrição foi recebida quando as vagas já estavam esgotadas.
                  Ela fica na lista de espera, por ordem de chegada. Se uma vaga for liberada, a Comissão chama
                  a próxima inscrição da fila e você recebe um e-mail.</p>
                  <div class="festa-info-box">
                    <strong>Enquanto espera:</strong> você já pode assinar e enviar a ficha de inscrição.
                    Assim, quando for chamado, a análise começa mais rápido.
                  </div>

                  <div class="festa-sub-block">
                    <h4>Ficha de inscrição</h4>
                    <?php
                    echo self::doc_link(
                        $post_id,
                        '_upload_ficha_gerada',
                        'Abrir a ficha de inscrição',
                        'Ficha e termo de responsabilidade, prontos para imprimir'
                    );

                    if ($ficha_env) {
                        echo '<p class="festa-notice festa-notice--success">&#10003; Ficha assinada enviada'
                            . esc_html(self::sent_when($post_id, '_upload_ficha_assinada')) . '.</p>';
                        echo '<details class="festa-details"><summary>Enviar outra versão da ficha</summary>'
                            . self::upload_form($post_id, 'ficha_assinada', 'Ficha de inscrição assinada', 'Substituir ficha')
                            . '</details>';
                    } else {
                        echo self::upload_form($post_id, 'ficha_assinada', 'Ficha de inscrição assinada', 'Enviar ficha assinada');
                    }
                    ?>
                  </div>
                </div>
                <?php
                break;

            // ------------------------------------------------------------------
            // INDEFERIDO: decisão da Comissão, com a justificativa. Sem envios.
            // ------------------------------------------------------------------
            case StateMachine::STATUS_INDEFERIDO:
                $justificativa = (string) get_post_meta($post_id, '_indeferimento_motivo', true);
                $quando        = (string) get_post_meta($post_id, '_indeferido_em', true);
                ?>
                <div class="festa-step-block">
                  <h3 class="festa-step-title">Inscrição indeferida</h3>
                  <p class="festa-text">A Comissão Organizadora analisou a sua inscrição e decidiu pelo indeferimento<?php
                    echo $quando !== '' ? esc_html(' em ' . mysql2date('d/m/Y', $quando)) : '';
                  ?>.</p>
                  <?php if ($justificativa !== '') : ?>
                    <div class="festa-indeferido-box" role="note">
                      <strong>Justificativa da Comissão</strong>
                      <p><?php echo nl2br(esc_html($justificativa)); ?></p>
                    </div>
                  <?php endif; ?>
                  <div class="festa-info-box">
                    Com o indeferimento, a inscrição não recebe novos envios.
                    Se houve um engano ou se a documentação foi regularizada, procure a SMDES:
                    só a Comissão pode reabrir a inscrição para nova análise.
                  </div>
                </div>
                <?php
                break;

            // ------------------------------------------------------------------
            // DEFERIDO: aguardando sorteio de tenda
            // ------------------------------------------------------------------
            case StateMachine::STATUS_DEFERIDO:
                ?>
                <div class="festa-step-block">
                  <h3 class="festa-step-title">Inscrição deferida &#10003;</h3>
                  <p class="festa-text">Parabéns! Sua inscrição foi deferida pela Comissão. Agora aguarde o sorteio
                  das tendas. O número da sua tenda será informado por e-mail em breve.</p>
                  <div class="festa-info-box">
                    <strong>Próximo passo:</strong> após o sorteio, você receberá o número da sua
                    tenda e poderá enviar o recibo de pagamento por aqui.
                  </div>
                </div>
                <?php
                break;

            // ------------------------------------------------------------------
            // AGUARDANDO COMPROVANTES: tenda sorteada + recibo + colchões
            // ------------------------------------------------------------------
            case StateMachine::STATUS_COMPROVANTES:
                $numero_tenda = (string) get_post_meta($post_id, '_numero_tenda', true);
                $recibo_env   = Handler::path($post_id, '_upload_recibo_tenda') !== '';
                $recibo_ok    = (bool) get_post_meta($post_id, '_tenda_aprovada', true);
                $colchoes     = (bool) get_post_meta($post_id, '_colchoes_confirmados', true);
                ?>
                <div class="festa-step-block">
                  <h3 class="festa-step-title">Sua tenda foi sorteada!</h3>

                  <div class="festa-tenda-destaque">
                    Tenda nº <span><?php echo esc_html($numero_tenda ?: '—'); ?></span>
                  </div>

                  <!-- Comprovante 1: Recibo da Tenda -->
                  <div class="festa-sub-block">
                    <h4>Comprovante 1 — Recibo da tenda</h4>
                    <?php if ($recibo_ok) : ?>
                      <p class="festa-notice festa-notice--success">&#10003; Recibo aprovado pela Comissão.</p>
                    <?php elseif ($recibo_env) : ?>
                      <p class="festa-notice festa-notice--success">&#10003; Recibo enviado<?php echo esc_html(self::sent_when($post_id, '_upload_recibo_tenda')); ?>.
                      Aguardando aprovação da Comissão.</p>
                      <details class="festa-details">
                        <summary>Enviar outro arquivo</summary>
                        <?php echo self::upload_form($post_id, 'recibo_tenda', 'Recibo da tenda', 'Substituir recibo'); ?>
                      </details>
                    <?php else : ?>
                      <p class="festa-text">Envie o comprovante de pagamento referente à Tenda nº
                      <strong><?php echo esc_html($numero_tenda); ?></strong>. Pode ser uma foto nítida ou um PDF.</p>
                      <?php echo self::upload_form($post_id, 'recibo_tenda', 'Recibo da tenda', 'Enviar recibo'); ?>
                    <?php endif; ?>
                  </div>

                  <!-- Comprovante 2: Doação de Colchões -->
                  <div class="festa-sub-block">
                    <h4>Comprovante 2 — Doação de colchões</h4>
                    <?php if ($colchoes) : ?>
                      <p class="festa-notice festa-notice--success">&#10003; Doação de colchões confirmada pela Defesa Civil.</p>
                      <?php
                      echo self::doc_link(
                          $post_id,
                          '_upload_comprovante_doacao',
                          'Abrir o comprovante de doação',
                          'Emitido pela Defesa Civil na confirmação da entrega'
                      );
                      ?>
                    <?php else : ?>
                      <p class="festa-text">Entregue os colchões no local abaixo.
                      Um agente confirmará o recebimento diretamente no sistema.</p>
                      <div class="festa-info-box">
                        <strong>Local:</strong> <?php echo esc_html(Settings::text('defesa_local')); ?><br>
                        <strong>Horário:</strong> <?php echo esc_html(Settings::text('defesa_horario')); ?><br>
                        <strong>Protocolo:</strong> nº <strong><?php echo (int) $post_id; ?></strong>
                      </div>
                    <?php endif; ?>
                  </div>

                </div>
                <?php
                break;

            // ------------------------------------------------------------------
            // CONCLUÍDO: download do Termo de Cessão
            // ------------------------------------------------------------------
            case StateMachine::STATUS_CONCLUIDO:
                ?>
                <div class="festa-step-block">
                  <h3 class="festa-step-title">Inscrição concluída!</h3>
                  <p class="festa-text">Todas as etapas foram cumpridas. Seu Termo de Cessão está disponível abaixo.
                  Guarde-o — pode ser solicitado na entrada do evento.</p>
                  <?php
                  echo self::doc_link(
                      $post_id,
                      '_upload_termo_cessao',
                      'Abrir o Termo de Cessão',
                      'Documento da cessão do espaço, pronto para imprimir',
                      172800 // 48h
                  );
                  echo self::doc_link(
                      $post_id,
                      '_upload_comprovante_doacao',
                      'Abrir o comprovante de doação',
                      'Emitido pela Defesa Civil na confirmação da entrega',
                      172800
                  );
                  ?>
                  <div class="festa-info-box">
                    Tenda nº <strong><?php echo esc_html((string) get_post_meta($post_id, '_numero_tenda', true)); ?></strong>
                    &nbsp;|&nbsp; Festa dos Amigos — <?php echo esc_html(Settings::text('data_evento')); ?>
                  </div>
                </div>
                <?php
                break;

            default:
                echo '<p class="festa-text">Status desconhecido. Entre em contato com a organização.</p>';
        }
    }
}
