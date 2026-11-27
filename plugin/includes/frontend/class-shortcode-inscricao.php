<?php

declare(strict_types=1);

namespace Festa\Frontend;

use Festa\Fields\Fields;
use Festa\Pages\Pages;
use Festa\Settings\Settings;
use Festa\Status\StateMachine;

/**
 * Shortcode [festa_inscricao]
 *
 * Roteamento baseado no estado de autenticação silo:
 *
 *   A) Não autenticado         → Exibe formulário de Login (CPF + Senha).
 *   B) Autenticado + rascunho  → Exibe formulário de finalização da inscrição.
 *   C) Autenticado + outro     → Aviso: inscrição já enviada, ir para o Painel.
 */
final class ShortcodeInscricao
{
    // -----------------------------------------------------------------------
    // Bootstrap
    // -----------------------------------------------------------------------

    public static function boot_hooks(): void
    {
        add_shortcode('festa_inscricao', [self::class, 'render']);
    }

    // -----------------------------------------------------------------------
    // Render principal
    // -----------------------------------------------------------------------

    public static function render(): string
    {
        $painel_url   = Pages::url('painel');
        $html         = Ui::open();
        $inscricao_id = AuthManager::get_current_inscricao_id();

        // --- Rota A: não autenticado ---
        if ($inscricao_id === null) {
            return $html . AuthManager::render_login_form() . Ui::close();
        }

        // --- Rota C: autenticado, status != rascunho ---
        $status = StateMachine::current($inscricao_id);
        if ($status !== StateMachine::STATUS_RASCUNHO) {
            $texto = match ($status) {
                StateMachine::STATUS_LISTA_ESPERA => 'Sua inscrição foi finalizada e está na lista de espera. Veja a sua posição na fila pelo painel.',
                StateMachine::STATUS_INDEFERIDO   => 'Sua inscrição foi analisada e indeferida pela Comissão. A justificativa está no painel.',
                default                           => 'Sua inscrição foi finalizada com sucesso e está em processamento. Acompanhe o andamento pelo painel.',
            };

            return $html . self::success_panel('Inscrição já enviada!', $texto, 'Ir para o painel', $painel_url, false)
                . Ui::close();
        }

        // --- Rota B: autenticado + rascunho → formulário de finalização ---
        if (! Settings::is_open()) {
            return $html . Ui::closed_card() . Ui::close();
        }

        $nome_responsavel = (string) get_post_meta($inscricao_id, '_nome_responsavel', true);

        $html .= Ui::form_open('festa_finalizar_inscricao', '#festa-finalizacao-success', true);
        $html .= '<p class="festa-notice">Olá, <strong>' . esc_html($nome_responsavel) . '</strong>! '
            . 'Preencha os dados abaixo para concluir a inscrição do seu grupo na Festa dos Amigos 2026.</p>';
        $html .= Ui::vagas_esgotadas_notice();

        // Contato
        $html .= Ui::card_open('1. Informações de contato', 'Por onde a Comissão avisa cada etapa da inscrição.');
        $html .= '<div class="festa-grid">';
        $html .= Ui::field_text('email', 'E-mail', ['type' => 'email', 'placeholder' => 'seu@email.com']);
        $html .= Ui::field_text('telefone', 'Telefone', ['type' => 'phone', 'placeholder' => '(00) 00000-0000']);
        $html .= '</div></div>';

        // Dados do Grupo
        $html .= Ui::card_open('2. Dados do grupo');
        $html .= Ui::field_text('grupo', 'Nome do grupo', ['placeholder' => 'Nome do seu grupo']);
        $html .= Ui::field_text('qtd_participantes', 'Nº de participantes', [
            'type'        => 'number',
            'placeholder' => 'Ex.: 20',
            'attrs'       => 'min="1" max="500"',
        ]);
        $html .= Ui::field_choice('coccao', 'Tipo de cocção', Fields::coccao());
        $html .= Ui::field_choice('espaco', 'Espaço necessário', Fields::espaco());
        $html .= '</div>';

        // Documentos
        $html .= Ui::card_open('3. Documentos', 'Opcionais nesta etapa — enviar agora agiliza a análise da Comissão.');
        $html .= Ui::field_file('identidade', 'Documento de identidade', 'doc', false, 'RG, CNH ou outro documento oficial com foto do responsável.');
        $html .= Ui::field_file('comprovante_residencia', 'Comprovante de residência', 'doc', false);
        $html .= '</div>';

        // Aceite de Termos (edital + LGPD)
        $edital   = Settings::text('link_edital');
        $politica = function_exists('get_privacy_policy_url') ? (string) get_privacy_policy_url() : '';
        $links    = [];
        if ($edital !== '') {
            $links[] = '<a href="' . esc_url($edital) . '" target="_blank" rel="noopener">Ler o edital ↗</a>';
        }
        if ($politica !== '') {
            $links[] = '<a href="' . esc_url($politica) . '" target="_blank" rel="noopener">Política de privacidade ↗</a>';
        }
        $html .= Ui::card_open('Termos do edital e LGPD');
        $html .= Ui::field_check('aceite', Fields::aceite_texto(), implode(' · ', $links));
        $html .= '</div>';

        $html .= Ui::form_close('Finalizar inscrição');

        // Confirmação
        $html .= self::success_panel(
            'Inscrição finalizada!',
            'Seus dados foram enviados para análise da Comissão Organizadora. Você será avisado por e-mail sobre o andamento.',
            'Acompanhar no painel',
            $painel_url,
            true
        );

        return $html . Ui::close();
    }

    /**
     * Confirmação da finalização. O título e o texto são trocados pela
     * resposta do servidor (data-festa-fill): com as vagas esgotadas, a
     * confirmação diz que a inscrição entrou na lista de espera.
     */
    private static function success_panel(string $title, string $text, string $button, string $url, bool $hidden): string
    {
        return '<div class="festa-success" id="festa-finalizacao-success" role="status"' . ($hidden ? ' hidden' : '') . '>'
            . '<div class="festa-success-icon" aria-hidden="true">&#10003;</div>'
            . '<h3' . ($hidden ? ' data-festa-fill="titulo"' : '') . '>' . esc_html($title) . '</h3>'
            . '<p class="festa-success-tip"' . ($hidden ? ' data-festa-fill="texto"' : '') . '>' . esc_html($text) . '</p>'
            . '<a class="festa-btn" href="' . esc_url($url) . '">' . esc_html($button) . ' &rarr;</a>'
            . '</div>';
    }
}
