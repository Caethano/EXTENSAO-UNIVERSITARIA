<?php

declare(strict_types=1);

namespace Festa\Pdf;

/**
 * Gerador de PDF minimalista, sem dependências externas.
 *
 * Produz PDF 1.4 com fontes core Helvetica/Helvetica-Bold em WinAnsiEncoding
 * (CP1252), que cobre todos os caracteres acentuados do pt-BR.
 * Usado pelo export de inscritos no painel (Panel::export_pdf).
 */
final class Writer
{
    // A4 em pontos.
    public const PAGE_W = 595.28;
    public const PAGE_H = 841.89;
    public const MARGIN = 48.0;
    public const LINE_H = 13.0;

    /**
     * Content stream de cada página.
     *
     * @var string[]
     */
    private $pages = [];

    /**
     * Posição vertical do cursor na página atual.
     *
     * @var float
     */
    private $y = 0.0;

    public function __construct()
    {
        $this->add_page();
    }

    public function add_page()
    {
        $this->pages[] = '';
        $this->y       = self::PAGE_H - self::MARGIN;
    }

    /**
     * Quebra de página automática quando não houver espaço para N linhas.
     */
    public function ensure_room($lines = 1)
    {
        if ($this->y - ($lines * self::LINE_H) < self::MARGIN) {
            $this->add_page();
        }
    }

    /**
     * Escreve uma linha de texto e avança o cursor.
     *
     * @param float $gray Tom do texto: 0 = preto … 1 = branco.
     */
    public function line($text, $size = 9.5, $bold = false, $indent = 0.0, $gray = 0.0)
    {
        $this->ensure_room();
        $this->y -= max(self::LINE_H, $size * 1.35);

        $this->pages[count($this->pages) - 1] .= sprintf(
            "%.2F g BT %s %.1F Tf %.2F %.2F Td (%s) Tj ET 0 g\n",
            $gray,
            $bold ? '/F2' : '/F1',
            $size,
            self::MARGIN + $indent,
            $this->y,
            self::escape($text)
        );
    }

    /**
     * Linha horizontal fina na posição atual do cursor (sem espaçamento extra).
     */
    public function hline()
    {
        $this->pages[count($this->pages) - 1] .= sprintf(
            "0.5 w 0.85 G %.2F %.2F m %.2F %.2F l S\n",
            self::MARGIN,
            $this->y,
            self::PAGE_W - self::MARGIN,
            $this->y
        );
    }

    /**
     * Linha de tabela rótulo/valor no estilo da tela de detalhe do painel:
     * coluna de rótulos com fundo creme, valor à direita, borda
     * inferior. Quebra automaticamente entre páginas quando o valor é longo.
     */
    public function table_row($label, $value, $label_w = 180.0)
    {
        $size    = 9.5;
        $pad     = 7.0;
        $usable  = self::PAGE_W - 2 * self::MARGIN;
        $value_w = $usable - $label_w;

        // Largura aproximada de caractere Helvetica ≈ 0,52 em.
        $label_chars = max(8, (int) floor(($label_w - 2 * $pad) / ($size * 0.52)));
        $value_chars = max(8, (int) floor(($value_w - 2 * $pad) / ($size * 0.52)));

        $label_lines = self::wrap($label, $label_chars);
        $value_lines = self::wrap('' === trim((string) $value) ? '—' : (string) $value, $value_chars);

        while ($label_lines || $value_lines) {
            $avail = (int) floor(($this->y - self::MARGIN - 2 * $pad) / self::LINE_H);
            if ($avail < 1) {
                $this->add_page();
                continue;
            }

            $l_chunk = array_splice($label_lines, 0, $avail);
            $v_chunk = array_splice($value_lines, 0, $avail);
            $row_h   = max(count($l_chunk), count($v_chunk), 1) * self::LINE_H + 2 * $pad;
            $yb      = $this->y - $row_h;
            $page    = count($this->pages) - 1;

            // Fundo creme da coluna de rótulos + borda inferior da linha.
            $this->pages[$page] .= sprintf(
                "1.000 0.969 0.910 rg %.2F %.2F %.2F %.2F re f 0 g\n",
                self::MARGIN,
                $yb,
                $label_w,
                $row_h
            );
            $this->pages[$page] .= sprintf(
                "0.5 w 0.85 G %.2F %.2F m %.2F %.2F l S\n",
                self::MARGIN,
                $yb,
                self::MARGIN + $usable,
                $yb
            );

            foreach ($l_chunk as $i => $txt) {
                $this->pages[$page] .= sprintf(
                    "BT /F2 %.1F Tf %.2F %.2F Td (%s) Tj ET\n",
                    $size,
                    self::MARGIN + $pad,
                    $this->y - $pad - $size - $i * self::LINE_H + 2,
                    self::escape($txt)
                );
            }
            foreach ($v_chunk as $i => $txt) {
                $this->pages[$page] .= sprintf(
                    "BT /F1 %.1F Tf %.2F %.2F Td (%s) Tj ET\n",
                    $size,
                    self::MARGIN + $label_w + $pad,
                    $this->y - $pad - $size - $i * self::LINE_H + 2,
                    self::escape($txt)
                );
            }

            $this->y = $yb;
        }
    }

    /**
     * Linha de grade com N colunas (usada na lista de presença).
     *
     * @param string[] $cells  Texto de cada coluna.
     * @param float[]  $widths Largura de cada coluna, em pontos.
     * @param bool     $bold   Usa a fonte negrito (cabeçalho da tabela).
     * @param bool     $fill   Pinta o fundo em verde-petróleo (cabeçalho).
     */
    public function grid_row($cells, $widths, $bold = false, $fill = false)
    {
        $size = 9.0;
        $pad  = 5.0;

        $lines = [];
        $max   = 1;
        foreach (array_values($cells) as $i => $text) {
            $chars     = max(4, (int) floor(($widths[$i] - 2 * $pad) / ($size * 0.52)));
            $wrapped   = self::wrap((string) $text, $chars);
            $lines[]   = $wrapped;
            $max       = max($max, count($wrapped));
        }

        $row_h = $max * self::LINE_H + 2 * $pad;
        if ($this->y - $row_h < self::MARGIN) {
            $this->add_page();
        }

        $yb    = $this->y - $row_h;
        $page  = count($this->pages) - 1;
        $total = array_sum($widths);

        if ($fill) {
            $this->pages[$page] .= sprintf(
                "0.043 0.365 0.420 rg %.2F %.2F %.2F %.2F re f 0 g\n",
                self::MARGIN,
                $yb,
                $total,
                $row_h
            );
        }

        $this->pages[$page] .= sprintf(
            "0.5 w 0.85 G %.2F %.2F m %.2F %.2F l S\n",
            self::MARGIN,
            $yb,
            self::MARGIN + $total,
            $yb
        );

        $x = self::MARGIN;
        foreach ($lines as $i => $cell) {
            foreach ($cell as $k => $txt) {
                $this->pages[$page] .= sprintf(
                    "%s BT %s %.1F Tf %.2F %.2F Td (%s) Tj ET 0 g\n",
                    $fill ? '1 g' : '0 g',
                    $bold ? '/F2' : '/F1',
                    $size,
                    $x + $pad,
                    $this->y - $pad - $size - $k * self::LINE_H + 2,
                    self::escape($txt)
                );
            }
            $x += $widths[$i];
        }

        $this->y = $yb;
    }

    /**
     * Há espaço na página atual para N linhas?
     */
    public function has_room($lines = 1)
    {
        return ($this->y - ($lines * self::LINE_H) - 10) >= self::MARGIN;
    }

    public function page_count()
    {
        return count($this->pages);
    }

    public function spacer($pt = 6.0)
    {
        $this->y -= $pt;
    }

    /**
     * Linha horizontal separadora.
     */
    public function rule()
    {
        $this->ensure_room();
        $this->y -= 8;
        $this->pages[count($this->pages) - 1] .= sprintf(
            "0.75 w 0.8 G %.2F %.2F m %.2F %.2F l S\n",
            self::MARGIN,
            $this->y,
            self::PAGE_W - self::MARGIN,
            $this->y
        );
        $this->y -= 4;
    }

    /**
     * Quebra texto em linhas de no máximo $width caracteres (por palavra;
     * palavras maiores que a largura — URLs — são cortadas sem invalidar UTF-8).
     *
     * @return string[]
     */
    public static function wrap($text, $width = 95)
    {
        $out = [];

        foreach (preg_split('/\r\n|\r|\n/', (string) $text) as $raw) {
            $line = trim($raw);
            if ('' === $line) {
                continue;
            }
            $cur = '';
            foreach (explode(' ', $line) as $word) {
                if ('' === $word) {
                    continue;
                }
                while (strlen($word) > $width) {
                    if ('' !== $cur) {
                        $out[] = $cur;
                        $cur   = '';
                    }
                    $chars = preg_split('//u', $word, -1, PREG_SPLIT_NO_EMPTY);
                    if (false === $chars || empty($chars)) {
                        $chars = str_split($word);
                    }
                    $out[] = implode('', array_slice($chars, 0, $width));
                    $word  = implode('', array_slice($chars, $width));
                }
                if ('' === $word) {
                    continue;
                }
                $try = '' === $cur ? $word : $cur . ' ' . $word;
                if (strlen($try) > $width && '' !== $cur) {
                    $out[] = $cur;
                    $cur   = $word;
                } else {
                    $cur = $try;
                }
            }
            if ('' !== $cur) {
                $out[] = $cur;
            }
        }

        return $out ? $out : [''];
    }

    /**
     * Converte UTF-8 → CP1252 e escapa os caracteres especiais de string PDF.
     */
    private static function escape($text)
    {
        $s    = (string) $text;
        $conv = false;

        if (function_exists('iconv')) {
            $conv = @iconv('UTF-8', 'CP1252//TRANSLIT', $s);
        }
        if (false === $conv && function_exists('mb_convert_encoding')) {
            $conv = @mb_convert_encoding($s, 'Windows-1252', 'UTF-8');
        }
        if (false === $conv || null === $conv) {
            $conv = preg_replace('/[^\x20-\x7E]/', '?', $s);
        }

        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $conv);
    }

    /**
     * Monta o documento final (binário) com numeração de páginas no rodapé.
     *
     * @return string
     */
    public function output()
    {
        $total = count($this->pages);
        foreach ($this->pages as $i => $content) {
            $this->pages[$i] = $content . sprintf(
                "BT /F1 8 Tf %.2F %.2F Td (%s) Tj ET\n",
                self::PAGE_W - self::MARGIN - 60,
                self::MARGIN - 20,
                self::escape(sprintf('Página %d de %d', $i + 1, $total))
            );
        }

        // Objetos: 1 catálogo, 2 árvore de páginas, 3/4 fontes, depois
        // um par (página + content stream) por página.
        $obj_num   = 5;
        $kids      = [];
        $page_objs = [];

        foreach ($this->pages as $content) {
            $page_num = $obj_num++;
            $cont_num = $obj_num++;
            $kids[]   = $page_num . ' 0 R';

            $page_objs[$page_num] = sprintf(
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>',
                self::PAGE_W,
                self::PAGE_H,
                $cont_num
            );
            $page_objs[$cont_num] = sprintf(
                "<< /Length %d >>\nstream\n%s\nendstream",
                strlen($content),
                $content
            );
        }

        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => sprintf('<< /Type /Pages /Kids [ %s ] /Count %d >>', implode(' ', $kids), $total),
            3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
        ] + $page_objs;
        ksort($objects);

        $pdf     = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $num => $body) {
            $offsets[$num] = strlen($pdf);
            $pdf            .= $num . " 0 obj\n" . $body . "\nendobj\n";
        }

        $xref_pos = strlen($pdf);
        $count    = count($objects) + 1;
        $pdf     .= "xref\n0 {$count}\n0000000000 65535 f \n";
        for ($i = 1; $i < $count; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $pdf .= "trailer\n<< /Size {$count} /Root 1 0 R >>\nstartxref\n{$xref_pos}\n%%EOF";

        return $pdf;
    }
}
