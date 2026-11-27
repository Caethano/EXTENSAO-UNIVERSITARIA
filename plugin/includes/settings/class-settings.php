<?php

declare(strict_types=1);

namespace Festa\Settings;

use DateTimeImmutable;
use Festa\Admin\Ctx;
use Festa\Admin\Panel;
use Festa\Audit\AuditLog;
use Festa\Status\Vagas;

/**
 * Configurações: abertura/encerramento das inscrições, limite de vagas,
 * textos, dados do evento exibidos ao participante e e-mails.
 */
final class Settings
{
    public const OPTION = 'festa_settings';

    public static function boot_hooks(): void
    {
        add_action('admin_post_festa_save_settings', [self::class, 'save']);
    }

    /** @return array<string, int|string> */
    public static function defaults(): array
    {
        return [
            'aberto'         => 1,
            'abre_em'        => '',
            'encerra_em'     => '',
            'limite_vagas'   => 0,
            'msg_encerrado'  => 'As inscrições para a Festa dos Amigos 2026 estão encerradas. Quem já fez o pré-cadastro continua acompanhando a inscrição pelo painel.',
            'apresentacao'   => 'Inscrição de grupos para a Festa dos Amigos 2026, conforme o Edital 001/2026/SMDES. Faça o pré-cadastro com o CPF do responsável, preencha os dados do grupo e acompanhe cada etapa pelo painel.',
            'link_edital'    => '',
            'data_evento'    => '11 de Abril de 2026',
            'defesa_local'   => 'Secretaria Municipal de Defesa Civil',
            'defesa_horario' => 'Seg–Sex, 08h–17h',
            'email_comissao' => '',
            'email_confirma' => 1,
        ];
    }

    /** @return ($key is null ? array<string, int|string> : int|string|null) */
    public static function get(?string $key = null): array|int|string|null
    {
        $opts = wp_parse_args((array) get_option(self::OPTION, []), self::defaults());

        if ($key === null) {
            return $opts;
        }

        return $opts[$key] ?? null;
    }

    public static function text(string $key): string
    {
        return (string) self::get($key);
    }

    /** Data/hora de um campo datetime-local das configurações (fuso do site) ou null. */
    private static function datetime(string $key): ?DateTimeImmutable
    {
        $raw = self::text($key);
        if ($raw === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($raw, wp_timezone());
        } catch (\Exception) {
            return null;
        }
    }

    /** Data/hora de encerramento (fuso do site) ou null. */
    public static function deadline(): ?DateTimeImmutable
    {
        return self::datetime('encerra_em');
    }

    /** Data/hora de abertura das inscrições (fuso do site) ou null. */
    public static function opens_at(): ?DateTimeImmutable
    {
        return self::datetime('abre_em');
    }

    /** As inscrições têm data de abertura que ainda não chegou? */
    public static function not_yet_open(): bool
    {
        $opens = self::opens_at();

        return $opens !== null && new DateTimeImmutable('now', wp_timezone()) < $opens;
    }

    /**
     * As inscrições estão abertas? Encerradas (ou antes da abertura), ninguém
     * faz pré-cadastro nem finaliza a inscrição; quem já enviou segue
     * normalmente pelo painel.
     */
    public static function is_open(): bool
    {
        if (! self::get('aberto') || self::not_yet_open()) {
            return false;
        }

        $deadline = self::deadline();

        return ! $deadline || new DateTimeImmutable('now', wp_timezone()) < $deadline;
    }

    /**
     * E-mails que recebem o aviso de nova inscrição para análise.
     * Sem nenhum configurado, vai para o e-mail do administrador do site.
     *
     * @return list<string>
     */
    public static function comissao_emails(): array
    {
        $emails = array_values(array_filter(array_map('trim', explode(',', self::text('email_comissao')))));

        return $emails ?: [(string) get_bloginfo('admin_email')];
    }

    // -----------------------------------------------------------------------
    // Salvar
    // -----------------------------------------------------------------------

    public static function save(): void
    {
        Ctx::require_post();
        Ctx::guard('config', 'festa_settings');

        // Só texto: descarta qualquer campo que chegue como lista.
        $in = array_filter((array) wp_unslash($_POST), 'is_string');

        $data_hora = static function (string $key) use ($in): string {
            $value = sanitize_text_field($in[$key] ?? '');

            return preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $value) ? $value : '';
        };
        $abre    = $data_hora('abre_em');
        $encerra = $data_hora('encerra_em');

        // Limite de vagas: inteiro ≥ 0 (0 = sem limite). Valor inválido não
        // é "corrigido" em silêncio: nada é salvo e a tela explica o erro.
        $limite = trim((string) ($in['limite_vagas'] ?? '0'));
        if ($limite === '') {
            $limite = '0';
        }
        if (! preg_match('/^\d{1,6}$/', $limite)) {
            self::back_with_error('limite');
        }
        if ($abre !== '' && $encerra !== '' && $abre >= $encerra) {
            self::back_with_error('periodo');
        }

        $emails = [];
        foreach ((array) preg_split('/[\s,;]+/', $in['email_comissao'] ?? '') as $email) {
            $email = sanitize_email((string) $email);
            if ($email !== '' && is_email($email)) {
                $emails[] = $email;
            }
        }

        $defaults = self::defaults();
        $text     = static function (string $key) use ($in, $defaults): string {
            $value = sanitize_text_field($in[$key] ?? '');

            return $value !== '' ? $value : (string) $defaults[$key];
        };

        $antes = self::get();

        update_option(self::OPTION, [
            'aberto'         => empty($in['aberto']) ? 0 : 1,
            'abre_em'        => $abre,
            'encerra_em'     => $encerra,
            'limite_vagas'   => (int) $limite,
            'msg_encerrado'  => sanitize_textarea_field($in['msg_encerrado'] ?? ''),
            'apresentacao'   => sanitize_textarea_field($in['apresentacao'] ?? ''),
            'link_edital'    => esc_url_raw($in['link_edital'] ?? ''),
            'data_evento'    => $text('data_evento'),
            'defesa_local'   => $text('defesa_local'),
            'defesa_horario' => $text('defesa_horario'),
            'email_comissao' => implode(', ', $emails),
            'email_confirma' => empty($in['email_confirma']) ? 0 : 1,
        ]);

        $detalhe = 'Configurações alteradas.';
        if ((int) $antes['limite_vagas'] !== (int) $limite) {
            $fmt     = static fn (int $n): string => $n > 0 ? (string) $n : 'sem limite';
            $detalhe .= sprintf(' Limite de vagas: %s → %s.', $fmt((int) $antes['limite_vagas']), $fmt((int) $limite));
        }
        AuditLog::record(0, 'configuracoes', $detalhe);

        Ctx::redirect(Ctx::url('config', ['saved' => 1]));
    }

    /** Volta para as Configurações sem salvar nada, com o motivo da recusa. */
    private static function back_with_error(string $erro): never
    {
        Ctx::redirect(Ctx::url('config', ['erro' => $erro]));
    }

    /** @return array<string, string> */
    private static function errors(): array
    {
        return [
            'limite'  => 'Nada foi salvo: o limite de vagas precisa ser um número inteiro igual ou maior que zero (0 = sem limite).',
            'periodo' => 'Nada foi salvo: a abertura das inscrições precisa ser antes do encerramento.',
        ];
    }

    // -----------------------------------------------------------------------
    // Tela
    // -----------------------------------------------------------------------

    public static function render(): void
    {
        if (! Ctx::can('config')) {
            Panel::no_access();
            return;
        }

        $o        = self::get();
        $open     = self::is_open();
        $deadline = self::deadline();
        $opens    = self::opens_at();
        $erro     = isset($_GET['erro']) && is_string($_GET['erro']) ? sanitize_key($_GET['erro']) : '';
        $ocupadas = Vagas::ocupadas();
        $limite   = (int) $o['limite_vagas'];
        ?>
        <div class="wrap festa-admin">
          <h1>Festa dos Amigos 2026 — Configurações</h1>

          <?php if (isset($_GET['saved'])) : ?>
            <div class="notice notice-success is-dismissible"><p>Configurações salvas.</p></div>
          <?php elseif (isset(self::errors()[$erro])) : ?>
            <div class="notice notice-error" role="alert"><p><?php echo esc_html(self::errors()[$erro]); ?></p></div>
          <?php endif; ?>

          <p class="festa-open-state <?php echo $open ? 'is-open' : 'is-closed'; ?>">
            <?php
            if ($open) {
                echo $deadline
                    ? esc_html('Inscrições abertas até ' . wp_date('d/m/Y \à\s H:i', $deadline->getTimestamp()) . '.')
                    : 'Inscrições abertas.';
            } elseif ($opens !== null && self::not_yet_open() && $o['aberto']) {
                echo esc_html('Inscrições ainda não abertas — abrem em ' . wp_date('d/m/Y \à\s H:i', $opens->getTimestamp()) . '.');
            } else {
                echo 'Inscrições encerradas — o pré-cadastro e a finalização mostram a mensagem de encerramento.';
            }
            ?>
          </p>

          <form method="post" action="<?php echo esc_url(Ctx::form_action()); ?>">
            <?php echo Ctx::form_fields('festa_save_settings', 'festa_settings'); ?>

            <h2 class="title">Inscrições</h2>
            <table class="form-table" role="presentation">
              <tr>
                <th scope="row">Inscrições</th>
                <td>
                  <label><input type="checkbox" name="aberto" value="1" <?php checked((bool) $o['aberto']); ?>> Receber inscrições</label>
                  <p class="description">Desmarque para encerrar manualmente. Quem já enviou a inscrição continua usando o painel.</p>
                </td>
              </tr>
              <tr>
                <th scope="row"><label for="festa-abre">Abrir automaticamente em</label></th>
                <td>
                  <input type="datetime-local" id="festa-abre" name="abre_em" value="<?php echo esc_attr((string) $o['abre_em']); ?>">
                  <p class="description">Opcional. Antes desta data o pré-cadastro avisa quando as inscrições abrem. Também é o início do gráfico “Inscrições por dia” do Dashboard.</p>
                </td>
              </tr>
              <tr>
                <th scope="row"><label for="festa-encerra">Encerrar automaticamente em</label></th>
                <td>
                  <input type="datetime-local" id="festa-encerra" name="encerra_em" value="<?php echo esc_attr((string) $o['encerra_em']); ?>">
                  <p class="description">Opcional. Horário de <?php echo esc_html(wp_timezone_string()); ?>. Deixe em branco para não ter prazo.</p>
                </td>
              </tr>
              <tr>
                <th scope="row"><label for="festa-limite">Limite de vagas</label></th>
                <td>
                  <input type="number" id="festa-limite" name="limite_vagas" min="0" max="999999" step="1" inputmode="numeric" class="small-text" value="<?php echo (int) $limite; ?>" required aria-describedby="festa-limite-desc">
                  <p class="description" id="festa-limite-desc">
                    Número máximo de inscrições deferidas. <strong>0 = sem limite.</strong>
                    Ocupam vaga as inscrições deferidas, aguardando comprovantes e concluídas
                    (hoje: <?php echo (int) $ocupadas; ?>).
                    Com as vagas esgotadas, a Comissão não consegue deferir e as novas inscrições entram na lista de espera.
                  </p>
                  <?php if ($limite > 0) : ?>
                    <p class="festa-vagas-resumo">
                      <?php
                      $restantes = max(0, $limite - $ocupadas);
                      echo esc_html(sprintf('Vagas restantes: %d de %d.', $restantes, $limite));
                      if ($ocupadas > $limite) {
                          echo ' ' . esc_html(sprintf('Há %d inscrições ocupando vaga acima do limite; nenhuma é desfeita.', $ocupadas - $limite));
                      }
                      ?>
                    </p>
                  <?php endif; ?>
                </td>
              </tr>
              <tr>
                <th scope="row"><label for="festa-msg-enc">Mensagem de encerramento</label></th>
                <td><textarea id="festa-msg-enc" name="msg_encerrado" rows="3" class="large-text"><?php echo esc_textarea((string) $o['msg_encerrado']); ?></textarea></td>
              </tr>
              <tr>
                <th scope="row"><label for="festa-apres">Texto de apresentação</label></th>
                <td>
                  <textarea id="festa-apres" name="apresentacao" rows="4" class="large-text"><?php echo esc_textarea((string) $o['apresentacao']); ?></textarea>
                  <p class="description">Aparece no topo do pré-cadastro. Uma linha em branco separa parágrafos.</p>
                </td>
              </tr>
              <tr>
                <th scope="row"><label for="festa-edital">Link do edital</label></th>
                <td>
                  <input type="url" id="festa-edital" name="link_edital" class="regular-text" value="<?php echo esc_attr((string) $o['link_edital']); ?>" placeholder="https://">
                  <p class="description">Opcional. Exibe o botão “Ler o edital” nos formulários.</p>
                </td>
              </tr>
            </table>

            <h2 class="title">Evento e entrega dos colchões</h2>
            <table class="form-table" role="presentation">
              <tr>
                <th scope="row"><label for="festa-data-evento">Data do evento</label></th>
                <td>
                  <input type="text" id="festa-data-evento" name="data_evento" class="regular-text" value="<?php echo esc_attr((string) $o['data_evento']); ?>">
                  <p class="description">Usada no painel do participante e no Termo de Cessão gerados a partir de agora.</p>
                </td>
              </tr>
              <tr>
                <th scope="row"><label for="festa-defesa-local">Local de entrega dos colchões</label></th>
                <td><input type="text" id="festa-defesa-local" name="defesa_local" class="regular-text" value="<?php echo esc_attr((string) $o['defesa_local']); ?>"></td>
              </tr>
              <tr>
                <th scope="row"><label for="festa-defesa-horario">Horário de entrega</label></th>
                <td><input type="text" id="festa-defesa-horario" name="defesa_horario" class="regular-text" value="<?php echo esc_attr((string) $o['defesa_horario']); ?>"></td>
              </tr>
            </table>

            <h2 class="title">E-mails</h2>
            <table class="form-table" role="presentation">
              <tr>
                <th scope="row">E-mail ao participante</th>
                <td>
                  <label><input type="checkbox" name="email_confirma" value="1" <?php checked((bool) $o['email_confirma']); ?>> Confirmar o recebimento da inscrição, com o número do protocolo</label>
                  <p class="description">Os avisos de deferimento, indeferimento, reabertura, lista de espera, tenda sorteada e conclusão são sempre enviados.</p>
                </td>
              </tr>
              <tr>
                <th scope="row"><label for="festa-email-comissao">Avisar a Comissão em</label></th>
                <td>
                  <input type="text" id="festa-email-comissao" name="email_comissao" class="regular-text" value="<?php echo esc_attr((string) $o['email_comissao']); ?>" placeholder="<?php echo esc_attr((string) get_bloginfo('admin_email')); ?>">
                  <p class="description">Quem recebe o aviso de nova inscrição para análise. Separe vários e-mails por vírgula. Em branco, vai para o e-mail do administrador do site.</p>
                </td>
              </tr>
            </table>

            <p class="submit"><button type="submit" class="button button-primary">Salvar configurações</button></p>
          </form>
        </div>
        <?php
    }
}
