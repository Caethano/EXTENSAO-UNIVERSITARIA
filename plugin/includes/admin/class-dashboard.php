<?php

declare(strict_types=1);

namespace Festa\Admin;

use DateInterval;
use DateTimeImmutable;
use Festa\Cpt\CptInscricao;
use Festa\Settings\Settings;
use Festa\Status\StateMachine;
use Festa\Status\Vagas;

/**
 * Dashboard: números das inscrições, sem nenhum dado pessoal.
 *
 * Cartões com o total, um por status e as vagas restantes, e o gráfico
 * "Inscrições por dia" (pré-cadastros), do início ao fim do período de
 * inscrições configurado. O gráfico é um SVG montado aqui no servidor — sem
 * biblioteca, sem script — e vem acompanhado de uma tabela com os mesmos
 * números (leitores de tela, impressão, quem não enxerga as cores).
 *
 * Permissão própria ("dashboard"): no wp-admin, a capability
 * festa_ver_dashboard (Comissão e administrador); no portal, a permissão da
 * conta. A Defesa Civil não tem acesso.
 */
final class Dashboard
{
    /** O gráfico cobre no máximo um ano (um prazo esquecido não gera milhares de barras). */
    private const MAX_DIAS = 366;

    // Geometria do gráfico (unidades do viewBox).
    private const W      = 960;
    private const H      = 300;
    private const PAD_L  = 44;
    private const PAD_R  = 12;
    private const PAD_T  = 22;
    private const PAD_B  = 40;
    private const BAR_MAX = 24;

    // -----------------------------------------------------------------------
    // Dados
    // -----------------------------------------------------------------------

    /**
     * Período do gráfico: da abertura configurada (ou do primeiro
     * pré-cadastro) ao encerramento configurado (ou hoje, ou o último
     * pré-cadastro, o que vier depois).
     *
     * @return array{0: DateTimeImmutable, 1: DateTimeImmutable}
     */
    public static function periodo(): array
    {
        global $wpdb;

        $tz   = wp_timezone();
        $hoje = new DateTimeImmutable('today', $tz);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $limites = $wpdb->get_row($wpdb->prepare(
            "SELECT MIN(post_date) AS primeiro, MAX(post_date) AS ultimo
               FROM {$wpdb->posts}
              WHERE post_type = %s AND post_status = 'publish'",
            CptInscricao::POST_TYPE
        ));

        $dia = static function (?string $mysql) use ($tz): ?DateTimeImmutable {
            return $mysql ? (new DateTimeImmutable($mysql, $tz))->setTime(0, 0) : null;
        };

        $primeiro = $dia($limites->primeiro ?? null);
        $ultimo   = $dia($limites->ultimo ?? null);

        $abre    = Settings::opens_at();
        $encerra = Settings::deadline();

        $inicio = $abre ? $abre->setTime(0, 0) : ($primeiro ?? $hoje);
        $fim    = $encerra ? $encerra->setTime(0, 0) : max($hoje, $ultimo ?? $hoje);

        if ($fim < $inicio) {
            $fim = $inicio;
        }
        if ((int) $inicio->diff($fim)->days >= self::MAX_DIAS) {
            $inicio = $fim->sub(new DateInterval('P' . (self::MAX_DIAS - 1) . 'D'));
        }

        return [$inicio, $fim];
    }

    /**
     * Pré-cadastros por dia no período, inclusive os dias sem nenhum.
     *
     * @return array<string, int> 'Y-m-d' => quantidade
     */
    public static function por_dia(DateTimeImmutable $inicio, DateTimeImmutable $fim): array
    {
        global $wpdb;

        $dias = [];
        for ($d = $inicio; $d <= $fim; $d = $d->add(new DateInterval('P1D'))) {
            $dias[$d->format('Y-m-d')] = 0;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT DATE(post_date) AS dia, COUNT(*) AS qtd
               FROM {$wpdb->posts}
              WHERE post_type = %s AND post_status = 'publish'
                AND post_date >= %s AND post_date < %s
           GROUP BY DATE(post_date)",
            CptInscricao::POST_TYPE,
            $inicio->format('Y-m-d 00:00:00'),
            $fim->add(new DateInterval('P1D'))->format('Y-m-d 00:00:00')
        ));

        foreach ($rows as $row) {
            if (isset($dias[(string) $row->dia])) {
                $dias[(string) $row->dia] = (int) $row->qtd;
            }
        }

        return $dias;
    }

    // -----------------------------------------------------------------------
    // Tela
    // -----------------------------------------------------------------------

    public static function render(): void
    {
        if (! Ctx::can('dashboard')) {
            Panel::no_access();
            return;
        }

        $por_status = Panel::count_by_status();
        $total      = array_sum($por_status);
        $limite     = Vagas::limite();
        $restantes  = Vagas::restantes();
        [$inicio, $fim] = self::periodo();
        $dias       = self::por_dia($inicio, $fim);
        $link       = Ctx::can('ver');
        ?>
        <div class="wrap festa-admin festa-dashboard">
          <h1>Festa dos Amigos 2026 — Dashboard</h1>
          <p class="festa-page-hint">
            Somente contagens: nenhum dado pessoal aparece nesta tela.
            Atualizado em <?php echo esc_html(wp_date('d/m/Y \à\s H:i')); ?>.
          </p>

          <div class="festa-dash-cards">
            <?php echo self::card('total', 'Total de inscrições', $total, $link ? Ctx::url('inscricoes') : ''); ?>
            <?php
            foreach (StateMachine::labels() as $status => $label) {
                echo self::card(
                    $status,
                    $label,
                    (int) ($por_status[$status] ?? 0),
                    $link ? Ctx::url('inscricoes', ['status' => $status]) : ''
                );
            }
            ?>
            <div class="festa-dash-card festa-dash-card--vagas" data-dash="vagas">
              <span class="festa-dash-label">Vagas restantes</span>
              <span class="festa-dash-num"><?php echo $restantes === null ? '—' : (int) $restantes; ?></span>
              <span class="festa-dash-sub"><?php echo esc_html($limite > 0 ? sprintf('Limite: %d', $limite) : 'Sem limite'); ?></span>
            </div>
          </div>

          <section class="festa-box festa-dash-chart" aria-labelledby="festa-dash-titulo">
            <h2 id="festa-dash-titulo">Inscrições por dia</h2>
            <p class="description">
              <?php
              echo esc_html(sprintf(
                  'Pré-cadastros por dia, de %s a %s%s.',
                  $inicio->format('d/m/Y'),
                  $fim->format('d/m/Y'),
                  Settings::opens_at() || Settings::deadline() ? ' (período de inscrições configurado)' : ''
              ));
              ?>
            </p>
            <?php echo self::svg($dias); ?>
            <details class="festa-dash-tabela">
              <summary>Ver os números em tabela</summary>
              <table class="widefat striped">
                <caption class="screen-reader-text">Inscrições por dia (pré-cadastros)</caption>
                <thead><tr><th scope="col">Dia</th><th scope="col">Inscrições</th></tr></thead>
                <tbody>
                  <?php foreach ($dias as $dia => $n) : ?>
                    <tr data-dia="<?php echo esc_attr($dia); ?>">
                      <th scope="row"><?php echo esc_html(self::fmt_dia($dia, true)); ?></th>
                      <td><?php echo (int) $n; ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
                <tfoot><tr><th scope="row">Total no período</th><td><?php echo (int) array_sum($dias); ?></td></tr></tfoot>
              </table>
            </details>
          </section>
        </div>
        <?php
    }

    private static function card(string $key, string $label, int $n, string $url): string
    {
        $num = '<span class="festa-dash-num">' . (int) $n . '</span>';

        return '<div class="festa-dash-card festa-dash-card--' . esc_attr($key) . '" data-dash="' . esc_attr($key) . '">'
            . '<span class="festa-dash-label">' . esc_html($label) . '</span>'
            . ($url !== '' ? '<a href="' . esc_url($url) . '" aria-label="' . esc_attr($label . ': ' . $n . ' — ver a lista') . '">' . $num . '</a>' : $num)
            . '</div>';
    }

    private static function fmt_dia(string $ymd, bool $ano = false): string
    {
        [$y, $m, $d] = explode('-', $ymd);

        return $d . '/' . $m . ($ano ? '/' . $y : '');
    }

    // -----------------------------------------------------------------------
    // Gráfico de barras em SVG
    // -----------------------------------------------------------------------

    /** Topo "redondo" da escala: 1, 2, 5, 10, 20, 50… acima do maior valor. */
    private static function escala(int $max): array
    {
        if ($max <= 0) {
            return [1, 1];
        }
        $bruto = $max / 4;
        $mag   = 10 ** (int) floor(log10(max(1, $bruto)));
        $passo = $mag;
        foreach ([1, 2, 5, 10] as $f) {
            if ($f * $mag >= $bruto) {
                $passo = $f * $mag;
                break;
            }
        }
        $passo = max(1, (int) $passo);

        return [(int) (ceil($max / $passo) * $passo), $passo];
    }

    /**
     * Uma barra por dia, crescendo da linha de base, com topo arredondado.
     * Cada dia tem uma faixa invisível da altura do gráfico com um <title>:
     * passar o mouse em qualquer ponto da coluna mostra o dia e o número.
     *
     * @param array<string, int> $dias
     */
    private static function svg(array $dias): string
    {
        $n      = max(1, count($dias));
        $max    = $dias ? max($dias) : 0;
        $total  = array_sum($dias);
        [$topo, $passo] = self::escala($max);

        $x0     = self::PAD_L;
        $y0     = self::H - self::PAD_B;              // linha de base
        $plot_w = self::W - self::PAD_L - self::PAD_R;
        $plot_h = $y0 - self::PAD_T;
        $faixa  = $plot_w / $n;
        $bar_w  = min(self::BAR_MAX, max(1.0, $faixa - 2)); // 2px de respiro entre barras
        $rotulo = (int) max(1, ceil($n / 12));              // no máximo ~12 datas no eixo

        $pico = array_search($max, $dias, true);
        $desc = $total === 0
            ? 'Nenhum pré-cadastro no período.'
            : sprintf(
                'Total de %d pré-cadastro(s) em %d dia(s). Dia com mais inscrições: %s, com %d.',
                $total,
                $n,
                self::fmt_dia((string) $pico, true),
                $max
            );

        $f   = static fn (float $v): string => rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
        $out = '<svg class="festa-dash-svg" viewBox="0 0 ' . self::W . ' ' . self::H . '" role="img"'
            . ' aria-labelledby="festa-dash-svg-t festa-dash-svg-d" preserveAspectRatio="xMidYMid meet">'
            . '<title id="festa-dash-svg-t">Inscrições por dia</title>'
            . '<desc id="festa-dash-svg-d">' . esc_html($desc) . '</desc>';

        // Grade e escala (recessivas).
        for ($v = 0; $v <= $topo; $v += $passo) {
            $y    = $y0 - ($v / $topo) * $plot_h;
            $out .= '<line class="festa-dash-grid" x1="' . $x0 . '" x2="' . (self::W - self::PAD_R) . '" y1="' . $f($y) . '" y2="' . $f($y) . '"/>'
                . '<text class="festa-dash-tick" x="' . ($x0 - 8) . '" y="' . $f($y + 4) . '" text-anchor="end">' . (int) $v . '</text>';
        }

        $i = 0;
        foreach ($dias as $dia => $qtd) {
            $cx   = $x0 + $faixa * ($i + 0.5);
            $dica = sprintf('%s: %d inscrição(ões)', self::fmt_dia($dia, true), $qtd);

            $out .= '<g class="festa-dash-dia">'
                . '<rect class="festa-dash-hit" x="' . $f($x0 + $faixa * $i) . '" y="' . self::PAD_T . '" width="' . $f($faixa) . '" height="' . $f($plot_h) . '"/>';

            if ($qtd > 0) {
                $h    = max(2.0, ($qtd / $topo) * $plot_h);
                $out .= '<path class="festa-dash-bar" d="' . self::barra($cx - $bar_w / 2, $y0, $bar_w, $h, $f) . '"/>';
                // Rótulo só no(s) pico(s): o resto fica na dica e na tabela.
                if ($qtd === $max) {
                    $out .= '<text class="festa-dash-valor" x="' . $f($cx) . '" y="' . $f($y0 - $h - 6) . '" text-anchor="middle">' . (int) $qtd . '</text>';
                }
            }
            $out .= '<title>' . esc_html($dica) . '</title></g>';

            if ($i % $rotulo === 0) {
                $out .= '<text class="festa-dash-tick" x="' . $f($cx) . '" y="' . ($y0 + 18) . '" text-anchor="middle">' . esc_html(self::fmt_dia($dia)) . '</text>';
            }
            $i++;
        }

        $out .= '<line class="festa-dash-base" x1="' . $x0 . '" x2="' . (self::W - self::PAD_R) . '" y1="' . $y0 . '" y2="' . $y0 . '"/>';

        return $out . '</svg>';
    }

    /** Caminho de uma barra: base reta, topo com cantos de 4 (ou menos, se a barra for baixa/estreita). */
    private static function barra(float $x, float $base, float $w, float $h, callable $f): string
    {
        $r   = min(4.0, $w / 2, $h);
        $top = $base - $h;

        return 'M' . $f($x) . ' ' . $f($base)
            . 'V' . $f($top + $r)
            . 'Q' . $f($x) . ' ' . $f($top) . ' ' . $f($x + $r) . ' ' . $f($top)
            . 'H' . $f($x + $w - $r)
            . 'Q' . $f($x + $w) . ' ' . $f($top) . ' ' . $f($x + $w) . ' ' . $f($top + $r)
            . 'V' . $f($base) . 'Z';
    }
}
