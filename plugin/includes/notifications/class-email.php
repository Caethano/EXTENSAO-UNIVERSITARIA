<?php

declare(strict_types=1);

namespace Festa\Notifications;

use Festa\Admin\AdminMenu;
use Festa\Pages\Pages;
use Festa\Settings\Settings;
use Festa\Status\StateMachine;
use Festa\Status\Vagas;

/**
 * Notificações transacionais por e-mail.
 *
 * Listener registrado no hook `festa_status_transition`.
 * Disparado automaticamente após cada transição de estado bem-sucedida.
 *
 * Regra de resiliência: falhas de envio NUNCA interrompem a execução.
 * O e-mail é best-effort; a transição de status já foi persistida.
 */
final class Email
{
    /**
     * E-mails da equipe SMDES/Comissão: os das Configurações ou, sem nenhum,
     * o do administrador do site.
     * Sobreposto via filtro `festa_email_comissao` para ambientes de staging/teste.
     */
    private const FILTRO_EMAIL_COMISSAO = 'festa_email_comissao';

    // -----------------------------------------------------------------------
    // Bootstrap
    // -----------------------------------------------------------------------

    public static function boot_hooks(): void
    {
        add_action(
            'festa_status_transition',
            [self::class, 'on_status_transition'],
            10,
            5
        );

        // Com a trava de vagas presa, os e-mails esperam ela ser liberada.
        add_action('festa_vagas_travadas',    [self::class, 'segurar']);
        add_action('festa_vagas_destravadas', [self::class, 'liberar']);
    }

    /** Quantas travas pediram para segurar os envios (0 = envia na hora). */
    private static int $segurando = 0;

    /** @var list<array{0: list<string>, 1: string, 2: string}> E-mails guardados: destinatários, assunto, corpo. */
    private static array $guardados = [];

    public static function segurar(): void
    {
        self::$segurando++;
    }

    /** Solta a trava e, se for a última, envia os e-mails guardados. */
    public static function liberar(): void
    {
        self::$segurando = max(0, self::$segurando - 1);
        if (self::$segurando > 0) {
            return;
        }

        $fila            = self::$guardados;
        self::$guardados = [];
        foreach ($fila as [$to, $subject, $body]) {
            self::send_html_email($to, $subject, $body);
        }
    }

    // -----------------------------------------------------------------------
    // Listener principal
    // -----------------------------------------------------------------------

    /**
     * Chamado automaticamente após cada transição de status bem-sucedida.
     *
     * @param int    $inscricao_id ID do post CPT.
     * @param string $status_atual Status anterior à transição.
     * @param string $novo_status  Status após a transição.
     * @param int    $user_id      ID do usuário que disparou a ação.
     * @param string $motivo       Justificativa do indeferimento ou motivo da reabertura.
     */
    public static function on_status_transition(
        int $inscricao_id,
        string $status_atual,
        string $novo_status,
        int $user_id,
        string $motivo = ''
    ): void {
        $email_participante = (string) get_post_meta($inscricao_id, '_email',           true);
        $nome_responsavel   = (string) get_post_meta($inscricao_id, '_nome_responsavel', true);
        $nome_grupo         = (string) get_post_meta($inscricao_id, '_nome_grupo',       true);
        $protocolo          = $inscricao_id;

        switch ($novo_status) {

            // ------------------------------------------------------------------
            // → Aguardando análise. Três caminhos:
            //  - finalização (rascunho): aviso à Comissão + confirmação ao participante;
            //  - chamada da lista de espera: aviso à Comissão + "você foi chamado";
            //  - reabertura de um indeferimento: só o participante é avisado
            //    (quem reabriu foi a própria Comissão).
            // ------------------------------------------------------------------
            case StateMachine::STATUS_ANALISE:
                if ($status_atual === StateMachine::STATUS_INDEFERIDO) {
                    if ($email_participante !== '') {
                        self::send_html_email(
                            $email_participante,
                            '[Festa dos Amigos 2026] Inscrição Reaberta para Análise — Protocolo #' . $protocolo,
                            self::template_reaberta($nome_responsavel, $nome_grupo, $protocolo, $motivo)
                        );
                    }
                    break;
                }

                $email_comissao = (array) apply_filters(
                    self::FILTRO_EMAIL_COMISSAO,
                    Settings::comissao_emails()
                );

                self::send_html_email(
                    $email_comissao,
                    '[Festa dos Amigos 2026] Nova Inscrição Pendente de Análise — Protocolo #' . $protocolo,
                    self::template_analise($nome_responsavel, $nome_grupo, $protocolo)
                );

                if ($status_atual === StateMachine::STATUS_LISTA_ESPERA) {
                    if ($email_participante !== '') {
                        self::send_html_email(
                            $email_participante,
                            '[Festa dos Amigos 2026] Você foi chamado da lista de espera — Protocolo #' . $protocolo,
                            self::template_chamado($nome_responsavel, $nome_grupo, $protocolo)
                        );
                    }
                    break;
                }

                // Confirmação ao participante, com o protocolo (pode ser desligada nas Configurações).
                if ($email_participante !== '' && Settings::get('email_confirma')) {
                    self::send_html_email(
                        $email_participante,
                        '[Festa dos Amigos 2026] Inscrição Recebida — Protocolo #' . $protocolo,
                        self::template_recebida($nome_responsavel, $nome_grupo, $protocolo)
                    );
                }
                break;

            // ------------------------------------------------------------------
            // Finalização com as vagas esgotadas: entrou na lista de espera.
            // Destinatário: Participante (sempre: ele precisa saber da fila).
            // ------------------------------------------------------------------
            case StateMachine::STATUS_LISTA_ESPERA:
                if ($email_participante === '') {
                    break;
                }
                self::send_html_email(
                    $email_participante,
                    '[Festa dos Amigos 2026] Inscrição na Lista de Espera — Protocolo #' . $protocolo,
                    self::template_lista_espera($nome_responsavel, $nome_grupo, $protocolo, Vagas::posicao($inscricao_id))
                );
                break;

            // ------------------------------------------------------------------
            // Indeferimento pela Comissão, com a justificativa.
            // Destinatário: Participante.
            // ------------------------------------------------------------------
            case StateMachine::STATUS_INDEFERIDO:
                if ($email_participante === '') {
                    break;
                }
                self::send_html_email(
                    $email_participante,
                    '[Festa dos Amigos 2026] Inscrição Indeferida — Protocolo #' . $protocolo,
                    self::template_indeferida($nome_responsavel, $nome_grupo, $protocolo, $motivo)
                );
                break;

            // ------------------------------------------------------------------
            // Fase 2 → 3: Inscrição deferida pela comissão.
            // Destinatário: Participante.
            // ------------------------------------------------------------------
            case StateMachine::STATUS_DEFERIDO:
                if ($email_participante === '') {
                    break;
                }
                self::send_html_email(
                    $email_participante,
                    '[Festa dos Amigos 2026] Documentação Deferida — Próximos Passos',
                    self::template_deferido($nome_responsavel, $nome_grupo, $protocolo)
                );
                break;

            // ------------------------------------------------------------------
            // Fase 3 → 4: Tenda sorteada, participante deve enviar comprovantes.
            // Destinatário: Participante.
            // ------------------------------------------------------------------
            case StateMachine::STATUS_COMPROVANTES:
                if ($email_participante === '') {
                    break;
                }
                $numero_tenda = (string) get_post_meta($inscricao_id, '_numero_tenda', true);
                self::send_html_email(
                    $email_participante,
                    '[Festa dos Amigos 2026] Número da Tenda Sorteada e Próximos Passos',
                    self::template_comprovantes($nome_responsavel, $nome_grupo, $protocolo, $numero_tenda)
                );
                break;

            // ------------------------------------------------------------------
            // Fase 4 → 5: Inscrição concluída, Termo de Cessão disponível.
            // Destinatário: Participante.
            // ------------------------------------------------------------------
            case StateMachine::STATUS_CONCLUIDO:
                if ($email_participante === '') {
                    break;
                }
                $painel_url = Pages::url('painel'); // Página com [festa_painel]
                self::send_html_email(
                    $email_participante,
                    '[Festa dos Amigos 2026] Inscrição Concluída — Termo de Cessão Disponível',
                    self::template_concluido($nome_responsavel, $nome_grupo, $protocolo, $painel_url)
                );
                break;
        }
    }

    // -----------------------------------------------------------------------
    // Utilitário de envio
    // -----------------------------------------------------------------------

    /**
     * Envia e-mail HTML via wp_mail().
     * Falhas são registradas em error_log e nunca propagadas como exceção.
     */
    private static function send_html_email(string|array $to, string $subject, string $html_body): void
    {
        $to = array_values(array_filter(array_map('strval', (array) $to)));
        if (! $to) {
            return;
        }

        if (self::$segurando > 0) {
            self::$guardados[] = [$to, $subject, $html_body];
            return;
        }

        $headers = ['Content-Type: text/html; charset=UTF-8'];

        $sent = wp_mail($to, $subject, $html_body, $headers);

        if (! $sent) {
            error_log(sprintf(
                '[Festa\Notifications\Email] Falha ao enviar e-mail. Destinatários: %d | Assunto: %s',
                count($to),
                $subject
            ));
        }
    }

    // -----------------------------------------------------------------------
    // Templates HTML dos e-mails
    // -----------------------------------------------------------------------

    /** Notifica a comissão sobre nova inscrição aguardando análise. */
    private static function template_analise(
        string $nome_responsavel,
        string $nome_grupo,
        int $protocolo
    ): string {
        $admin_url = esc_url(admin_url('admin.php?page=' . AdminMenu::SLUG . '&view=' . $protocolo));

        return self::wrap_template('Nova Inscrição Pendente de Análise', '
            <p>Uma nova inscrição foi finalizada e aguarda análise da comissão.</p>
            <table cellpadding="0" cellspacing="0" style="margin:24px 0;border-collapse:collapse">
              <tr>
                <td style="padding:8px 16px 8px 0;color:#6e6e73;font-size:14px;white-space:nowrap">Responsável</td>
                <td style="padding:8px 0;font-size:14px;font-weight:600">' . esc_html($nome_responsavel) . '</td>
              </tr>
              <tr>
                <td style="padding:8px 16px 8px 0;color:#6e6e73;font-size:14px;white-space:nowrap">Grupo</td>
                <td style="padding:8px 0;font-size:14px">' . esc_html($nome_grupo) . '</td>
              </tr>
              <tr>
                <td style="padding:8px 16px 8px 0;color:#6e6e73;font-size:14px;white-space:nowrap">Protocolo</td>
                <td style="padding:8px 0;font-size:14px;font-family:monospace">#' . $protocolo . '</td>
              </tr>
            </table>
            <p>
              <a href="' . $admin_url . '"
                 style="display:inline-block;padding:12px 24px;background:#0071e3;color:#fff;
                        border-radius:8px;text-decoration:none;font-size:15px;font-weight:600">
                Abrir a Inscrição
              </a>
            </p>
            <p style="margin-top:24px;font-size:13px;color:#6e6e73">
              Acesse a inscrição, confira os dados e os documentos, assine o deferimento e envie o arquivo.
            </p>
        ');
    }

    /** Confirma ao participante que a inscrição foi recebida e informa o protocolo. */
    private static function template_recebida(
        string $nome_responsavel,
        string $nome_grupo,
        int $protocolo
    ): string {
        $painel_url = esc_url(Pages::url('painel'));

        return self::wrap_template('Inscrição Recebida', '
            <p>Olá, <strong>' . esc_html($nome_responsavel) . '</strong>.</p>
            <p>
              Recebemos a inscrição do seu grupo na <strong>Festa dos Amigos 2026</strong>.
              Ela está agora em análise pela Comissão Organizadora.
            </p>
            <table cellpadding="0" cellspacing="0" style="margin:24px 0;border-collapse:collapse">
              <tr>
                <td style="padding:8px 16px 8px 0;color:#6e6e73;font-size:14px;white-space:nowrap">Grupo</td>
                <td style="padding:8px 0;font-size:14px;font-weight:600">' . esc_html($nome_grupo) . '</td>
              </tr>
              <tr>
                <td style="padding:8px 16px 8px 0;color:#6e6e73;font-size:14px;white-space:nowrap">Protocolo</td>
                <td style="padding:8px 0;font-size:14px;font-family:monospace">#' . $protocolo . '</td>
              </tr>
            </table>
            <p><strong>Próximos passos:</strong></p>
            <ul style="padding-left:20px;line-height:1.8">
              <li>No painel, abra a ficha de inscrição, assine e envie a ficha assinada.</li>
              <li>Você receberá um novo e-mail quando a inscrição for deferida.</li>
            </ul>
            <p>
              <a href="' . $painel_url . '"
                 style="display:inline-block;padding:12px 24px;background:#0071e3;color:#fff;
                        border-radius:8px;text-decoration:none;font-size:15px;font-weight:600">
                Acessar Meu Painel
              </a>
            </p>
            <p style="margin-top:24px;font-size:13px;color:#6e6e73">
              Guarde o número do protocolo. Para entrar no painel, use o CPF e a senha do pré-cadastro.
            </p>
        ');
    }

    /** Notifica o participante que a inscrição foi deferida. */
    private static function template_deferido(
        string $nome_responsavel,
        string $nome_grupo,
        int $protocolo
    ): string {
        return self::wrap_template('Documentação Deferida', '
            <p>Olá, <strong>' . esc_html($nome_responsavel) . '</strong>.</p>
            <p>
              Temos uma ótima notícia: a documentação do seu grupo foi <strong>analisada e deferida</strong>
              pela Comissão da Festa dos Amigos 2026.
            </p>
            <table cellpadding="0" cellspacing="0" style="margin:24px 0;border-collapse:collapse">
              <tr>
                <td style="padding:8px 16px 8px 0;color:#6e6e73;font-size:14px;white-space:nowrap">Grupo</td>
                <td style="padding:8px 0;font-size:14px;font-weight:600">' . esc_html($nome_grupo) . '</td>
              </tr>
              <tr>
                <td style="padding:8px 16px 8px 0;color:#6e6e73;font-size:14px;white-space:nowrap">Protocolo</td>
                <td style="padding:8px 0;font-size:14px;font-family:monospace">#' . $protocolo . '</td>
              </tr>
            </table>
            <p><strong>Próximos passos:</strong></p>
            <ul style="padding-left:20px;line-height:1.8">
              <li>Seu grupo está aprovado na primeira fase do processo.</li>
              <li>Aguarde o sorteio da tenda — você receberá um novo e-mail assim que o número for atribuído.</li>
              <li>Fique atento ao seu e-mail cadastrado neste endereço.</li>
            </ul>
            <p style="margin-top:24px;font-size:13px;color:#6e6e73">
              Em caso de dúvidas, entre em contato com a SMDES.
            </p>
        ');
    }

    /** Notifica o participante sobre o número de tenda sorteado e os comprovantes necessários. */
    private static function template_comprovantes(
        string $nome_responsavel,
        string $nome_grupo,
        int $protocolo,
        string $numero_tenda
    ): string {
        $painel_url = esc_url(Pages::url('painel'));

        return self::wrap_template('Número da Tenda Sorteada e Próximos Passos', '
            <p>Olá, <strong>' . esc_html($nome_responsavel) . '</strong>.</p>
            <p>O sorteio de tendas foi realizado. Veja o número atribuído ao seu grupo:</p>
            <div style="margin:24px 0;padding:24px;background:#f5f5f7;border-radius:12px;text-align:center">
              <p style="margin:0 0 4px;font-size:13px;color:#6e6e73;text-transform:uppercase;
                        letter-spacing:0.08em">Tenda do Grupo ' . esc_html($nome_grupo) . '</p>
              <p style="margin:0;font-size:48px;font-weight:700;color:#0071e3;line-height:1">'
                  . (esc_html($numero_tenda) ?: '—') . '</p>
            </div>
            <p><strong>Para concluir sua inscrição, você precisa enviar:</strong></p>
            <ol style="padding-left:20px;line-height:1.8">
              <li><strong>Recibo de pagamento da tenda</strong> (foto ou PDF): comprovante do pagamento referente à locação da tenda sorteada.</li>
              <li><strong>Doação física de colchões</strong>: entregue os colchões ao posto da Defesa Civil. A confirmação será feita diretamente por eles no sistema.</li>
            </ol>
            <p>
              <a href="' . $painel_url . '"
                 style="display:inline-block;padding:12px 24px;background:#0071e3;color:#fff;
                        border-radius:8px;text-decoration:none;font-size:15px;font-weight:600">
                Acessar Meu Painel
              </a>
            </p>
            <p style="margin-top:24px;font-size:13px;color:#6e6e73">
              Protocolo: #' . $protocolo . '
            </p>
        ');
    }

    /** Notifica o participante que a inscrição foi concluída e o Termo de Cessão está disponível. */
    private static function template_concluido(
        string $nome_responsavel,
        string $nome_grupo,
        int $protocolo,
        string $painel_url
    ): string {
        return self::wrap_template('Inscrição Concluída — Termo de Cessão Disponível', '
            <p>Olá, <strong>' . esc_html($nome_responsavel) . '</strong>.</p>
            <p>
              Parabéns! A inscrição do seu grupo na <strong>Festa dos Amigos 2026</strong>
              foi <strong>concluída com sucesso</strong>.
            </p>
            <table cellpadding="0" cellspacing="0" style="margin:24px 0;border-collapse:collapse">
              <tr>
                <td style="padding:8px 16px 8px 0;color:#6e6e73;font-size:14px;white-space:nowrap">Grupo</td>
                <td style="padding:8px 0;font-size:14px;font-weight:600">' . esc_html($nome_grupo) . '</td>
              </tr>
              <tr>
                <td style="padding:8px 16px 8px 0;color:#6e6e73;font-size:14px;white-space:nowrap">Protocolo</td>
                <td style="padding:8px 0;font-size:14px;font-family:monospace">#' . $protocolo . '</td>
              </tr>
            </table>
            <p>
              Seu <strong>Termo de Cessão de Espaço</strong> está disponível para download no seu painel.
              Este documento é obrigatório — guarde-o e apresente-o no dia do evento, se solicitado.
            </p>
            <p>
              <a href="' . esc_url($painel_url) . '"
                 style="display:inline-block;padding:12px 24px;background:#0071e3;color:#fff;
                        border-radius:8px;text-decoration:none;font-size:15px;font-weight:600">
                Baixar Termo de Cessão
              </a>
            </p>
            <p style="margin-top:24px;font-size:13px;color:#6e6e73">
              Nos vemos na Festa dos Amigos 2026!<br>
              Secretaria Municipal de Desenvolvimento Social — SMDES
            </p>
        ');
    }

    /** Linhas "Grupo" e "Protocolo" usadas nos avisos ao participante. */
    private static function tabela_grupo(string $nome_grupo, int $protocolo): string
    {
        return '
            <table cellpadding="0" cellspacing="0" style="margin:24px 0;border-collapse:collapse">
              <tr>
                <td style="padding:8px 16px 8px 0;color:#6e6e73;font-size:14px;white-space:nowrap">Grupo</td>
                <td style="padding:8px 0;font-size:14px;font-weight:600">' . esc_html($nome_grupo) . '</td>
              </tr>
              <tr>
                <td style="padding:8px 16px 8px 0;color:#6e6e73;font-size:14px;white-space:nowrap">Protocolo</td>
                <td style="padding:8px 0;font-size:14px;font-family:monospace">#' . $protocolo . '</td>
              </tr>
            </table>';
    }

    /** Texto digitado pela Comissão, escapado, num bloco destacado. */
    private static function bloco_texto(string $titulo, string $texto): string
    {
        return '
            <div style="margin:16px 0 24px;padding:16px 20px;background:#f5f5f7;border-left:4px solid #b42318;border-radius:0 8px 8px 0">
              <p style="margin:0 0 6px;font-size:13px;color:#6e6e73;text-transform:uppercase;letter-spacing:0.06em">' . esc_html($titulo) . '</p>
              <p style="margin:0;font-size:15px;line-height:1.6">' . nl2br(esc_html($texto)) . '</p>
            </div>';
    }

    private static function botao_painel(string $rotulo): string
    {
        return '
            <p>
              <a href="' . esc_url(Pages::url('painel')) . '"
                 style="display:inline-block;padding:12px 24px;background:#0071e3;color:#fff;
                        border-radius:8px;text-decoration:none;font-size:15px;font-weight:600">
                ' . esc_html($rotulo) . '
              </a>
            </p>';
    }

    /** Avisa o participante que a inscrição foi indeferida, com a justificativa da Comissão. */
    private static function template_indeferida(
        string $nome_responsavel,
        string $nome_grupo,
        int $protocolo,
        string $justificativa
    ): string {
        return self::wrap_template('Inscrição Indeferida', '
            <p>Olá, <strong>' . esc_html($nome_responsavel) . '</strong>.</p>
            <p>
              A Comissão da <strong>Festa dos Amigos 2026</strong> analisou a inscrição do seu grupo
              e decidiu pelo <strong>indeferimento</strong>.
            </p>' . self::tabela_grupo($nome_grupo, $protocolo)
            . self::bloco_texto('Justificativa da Comissão', $justificativa) . '
            <p>
              Com o indeferimento, a inscrição não recebe novos envios pelo painel.
              Se você acredita que houve um engano ou se a documentação foi regularizada,
              entre em contato com a SMDES: só a Comissão pode reabrir a inscrição para nova análise.
            </p>' . self::botao_painel('Ver a inscrição no painel') . '
            <p style="margin-top:24px;font-size:13px;color:#6e6e73">
              Secretaria Municipal de Desenvolvimento Social — SMDES
            </p>
        ');
    }

    /** Avisa o participante que a Comissão reabriu a inscrição indeferida. */
    private static function template_reaberta(
        string $nome_responsavel,
        string $nome_grupo,
        int $protocolo,
        string $motivo
    ): string {
        return self::wrap_template('Inscrição Reaberta para Análise', '
            <p>Olá, <strong>' . esc_html($nome_responsavel) . '</strong>.</p>
            <p>
              A Comissão <strong>reabriu</strong> a inscrição do seu grupo, que volta a ficar
              <strong>aguardando análise</strong>.
            </p>' . self::tabela_grupo($nome_grupo, $protocolo)
            . self::bloco_texto('Motivo da reabertura', $motivo) . '
            <p>Acompanhe pelo painel: você receberá um novo e-mail quando a análise for concluída.</p>'
            . self::botao_painel('Acessar Meu Painel') . '
        ');
    }

    /** Confirma ao participante que a inscrição entrou na lista de espera, com a posição. */
    private static function template_lista_espera(
        string $nome_responsavel,
        string $nome_grupo,
        int $protocolo,
        int $posicao
    ): string {
        return self::wrap_template('Inscrição na Lista de Espera', '
            <p>Olá, <strong>' . esc_html($nome_responsavel) . '</strong>.</p>
            <p>
              Recebemos a inscrição do seu grupo na <strong>Festa dos Amigos 2026</strong>.
              Como as vagas estão esgotadas no momento, ela entrou na <strong>lista de espera</strong>,
              por ordem de chegada.
            </p>' . self::tabela_grupo($nome_grupo, $protocolo)
            . ($posicao > 0 ? '
            <div style="margin:24px 0;padding:20px;background:#f5f5f7;border-radius:12px;text-align:center">
              <p style="margin:0 0 4px;font-size:13px;color:#6e6e73;text-transform:uppercase;letter-spacing:0.08em">Sua posição na fila</p>
              <p style="margin:0;font-size:40px;font-weight:700;color:#0071e3;line-height:1">' . (int) $posicao . 'º</p>
            </div>' : '') . '
            <p>
              Se uma vaga for liberada, a Comissão chama a próxima inscrição da fila e você recebe um e-mail.
              A posição atual fica sempre visível no painel.
            </p>' . self::botao_painel('Acompanhar no painel') . '
        ');
    }

    /** Avisa o participante que a inscrição saiu da lista de espera e vai para análise. */
    private static function template_chamado(
        string $nome_responsavel,
        string $nome_grupo,
        int $protocolo
    ): string {
        return self::wrap_template('Você foi chamado da lista de espera', '
            <p>Olá, <strong>' . esc_html($nome_responsavel) . '</strong>.</p>
            <p>
              Boa notícia: uma vaga foi liberada e a inscrição do seu grupo <strong>saiu da lista de espera</strong>.
              Ela agora está <strong>aguardando a análise</strong> da Comissão Organizadora.
            </p>' . self::tabela_grupo($nome_grupo, $protocolo) . '
            <p><strong>Próximos passos:</strong></p>
            <ul style="padding-left:20px;line-height:1.8">
              <li>No painel, abra a ficha de inscrição, assine e envie a ficha assinada (se ainda não enviou).</li>
              <li>Você receberá um novo e-mail quando a análise for concluída.</li>
            </ul>' . self::botao_painel('Acessar Meu Painel') . '
        ');
    }

    // -----------------------------------------------------------------------
    // Wrapper HTML base (compatível com clientes de e-mail)
    // -----------------------------------------------------------------------

    /**
     * Envolve o conteúdo em um layout HTML mínimo e seguro para clientes de e-mail.
     * Sem CSS externo, sem fontes remotas — apenas inline styles e system fonts.
     */
    private static function wrap_template(string $title, string $content): string
    {
        $site_name = esc_html(get_bloginfo('name'));

        return '<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>' . esc_html($title) . '</title>
</head>
<body style="margin:0;padding:0;background:#f5f5f7;font-family:-apple-system,BlinkMacSystemFont,
             \'Segoe UI\',Helvetica,Arial,sans-serif;color:#1d1d1f;-webkit-font-smoothing:antialiased">
  <table cellpadding="0" cellspacing="0" width="100%" style="background:#f5f5f7;padding:40px 16px">
    <tr>
      <td align="center">
        <table cellpadding="0" cellspacing="0" width="600"
               style="max-width:600px;background:#fff;border-radius:16px;
                      box-shadow:0 1px 3px rgba(0,0,0,.08);overflow:hidden">

          <!-- Cabeçalho -->
          <tr>
            <td style="background:#0071e3;padding:28px 40px">
              <p style="margin:0;font-size:13px;color:rgba(255,255,255,.7);
                        text-transform:uppercase;letter-spacing:0.1em">Festa dos Amigos 2026</p>
              <h1 style="margin:4px 0 0;font-size:22px;font-weight:700;color:#fff;line-height:1.3">
                ' . esc_html($title) . '
              </h1>
            </td>
          </tr>

          <!-- Corpo -->
          <tr>
            <td style="padding:32px 40px;font-size:15px;line-height:1.6;color:#1d1d1f">
              ' . $content . '
            </td>
          </tr>

          <!-- Rodapé -->
          <tr>
            <td style="padding:20px 40px 28px;border-top:1px solid #f5f5f7;
                       font-size:12px;color:#6e6e73;line-height:1.5">
              <p style="margin:0">
                Este é um e-mail automático do sistema de inscrições da ' . $site_name . '.
                Não responda a esta mensagem.
              </p>
            </td>
          </tr>

        </table>
      </td>
    </tr>
  </table>
</body>
</html>';
    }
}
