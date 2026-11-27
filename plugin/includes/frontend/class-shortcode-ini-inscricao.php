<?php

declare(strict_types=1);

namespace Festa\Frontend;

use Festa\Pages\Pages;
use Festa\Settings\Settings;

/**
 * Shortcode [ini_inscricao]
 *
 * Formulário de pré-cadastro: CPF e Nome Completo.
 * Cria o CPT inscricao_festa com status 'rascunho' e uma senha de acesso
 * sorteada, mostrada uma única vez. Após o sucesso, o participante já sai
 * autenticado e é orientado a seguir para a página de finalização.
 *
 * Atributos:
 *   inscricao_url  — URL da página [festa_inscricao]. Padrão: a página criada pelo plugin.
 */
final class ShortcodeIniInscricao
{
    // -----------------------------------------------------------------------
    // Bootstrap
    // -----------------------------------------------------------------------

    public static function boot_hooks(): void
    {
        add_shortcode('ini_inscricao', [self::class, 'render']);
    }

    // -----------------------------------------------------------------------
    // Render
    // -----------------------------------------------------------------------

    public static function render(array|string $atts): string
    {
        $atts = shortcode_atts(
            ['inscricao_url' => Pages::url('inscricao')],
            (array) $atts,
            'ini_inscricao'
        );

        $inscricao_url = (string) $atts['inscricao_url'];
        $html          = Ui::open();

        // Se já há sessão ativa, encaminha direto para a finalização
        if (AuthManager::get_current_inscricao_id() !== null) {
            // Envio sem JavaScript: a senha recém-criada chega aqui, uma única vez.
            $senha = AuthManager::flash_get('senha');

            if ($senha !== '') {
                return $html . self::success_panel($inscricao_url, $senha) . Ui::close();
            }

            return $html
                . Ui::card_open('Pré-cadastro')
                . '<p class="festa-text">Você já possui um pré-cadastro ativo neste navegador.</p>'
                . '<div class="festa-actions">'
                . '<a class="festa-btn" href="' . esc_url($inscricao_url) . '">Continuar a inscrição &rarr;</a>'
                . '<a class="festa-btn festa-btn--ghost" href="' . esc_url(Pages::url('painel')) . '">Ir para o painel</a>'
                . '</div></div>'
                . Ui::close();
        }

        $html .= Ui::intro_card();

        $ja_tenho = '<p class="festa-login-hint">Já fez o pré-cadastro? '
            . '<a href="' . esc_url($inscricao_url) . '">Entre com o CPF e a senha</a> para continuar.</p>';

        if (! Settings::is_open()) {
            return $html . Ui::closed_card($ja_tenho) . Ui::close();
        }

        // Formulário de pré-cadastro
        $html .= Ui::card_open(
            'Pré-cadastro',
            'Informe o CPF e o nome completo do responsável pelo grupo. Ao concluir, você recebe a senha de acesso da sua inscrição.'
        );
        $html .= Ui::vagas_esgotadas_notice();
        $html .= Ui::form_open('festa_auth_ini_inscricao', '#festa-ini-success');
        $html .= '<input type="text" name="festa_hp" class="festa-hp" tabindex="-1" autocomplete="off" aria-hidden="true">';
        $html .= Ui::field_text('cpf', 'CPF', ['type' => 'cpf', 'placeholder' => '000.000.000-00']);
        $html .= Ui::field_text('nome', 'Nome completo', ['placeholder' => 'Nome completo do responsável', 'attrs' => 'autocomplete="name"']);
        $html .= Ui::form_close('Fazer pré-cadastro');
        $html .= $ja_tenho . '</div>';

        // Confirmação (oculta inicialmente; preenchida com a resposta do servidor)
        $html .= self::success_panel($inscricao_url, '');

        return $html . Ui::close();
    }

    /**
     * Confirmação do pré-cadastro, com a senha de acesso.
     * Com $senha vazia, o painel nasce oculto e o JavaScript o preenche.
     */
    private static function success_panel(string $inscricao_url, string $senha): string
    {
        ob_start();
        ?>
        <div class="festa-success" id="festa-ini-success" role="status"<?php echo $senha === '' ? ' hidden' : ''; ?>>
          <div class="festa-success-icon" aria-hidden="true">&#10003;</div>
          <h3>Pré-cadastro realizado!</h3>
          <p class="festa-success-lead">
            Guarde a senha abaixo: com ela e o CPF você volta à sua inscrição quando quiser.
          </p>
          <div class="festa-senha-box">
            <p class="festa-senha-label">Sua senha de acesso</p>
            <strong class="festa-senha" id="festa-senha" data-festa-fill="senha"><?php echo esc_html($senha); ?></strong>
            <button type="button" class="festa-btn festa-btn--ghost" data-festa-copy="#festa-senha">Copiar senha</button>
          </div>
          <p class="festa-success-tip">
            <strong>Ela não será mostrada de novo.</strong> Anote ou tire uma foto desta tela.
            Depois, se preferir, troque por uma senha sua no painel.
          </p>
          <a class="festa-btn" href="<?php echo esc_url($inscricao_url); ?>">Continuar para a inscrição &rarr;</a>
        </div>
        <?php
        return (string) ob_get_clean();
    }
}
