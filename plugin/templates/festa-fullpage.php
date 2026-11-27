<?php
/**
 * Modelo de página inteira das páginas do participante da Festa dos Amigos.
 * Ocupa toda a tela, sem o cabeçalho/rodapé do tema.
 */

declare(strict_types=1);

defined('ABSPATH') || exit;
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
  <meta charset="<?php bloginfo('charset'); ?>">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <?php wp_head(); ?>
</head>
<body <?php body_class('festa-fullpage'); ?>>

<div class="festa-fullpage-bg" aria-hidden="true">
  <span class="festa-bg-glow festa-bg-glow--1"></span>
  <span class="festa-bg-glow festa-bg-glow--2"></span>
  <span class="festa-bg-glow festa-bg-glow--3"></span>
</div>

<main class="festa-fullpage-main">
  <a class="festa-back-home" href="<?php echo esc_url(home_url('/')); ?>">← Voltar ao site</a>
  <?php
  while (have_posts()) :
      the_post();
      the_content();
  endwhile;
  ?>
</main>

<?php wp_footer(); ?>
</body>
</html>
