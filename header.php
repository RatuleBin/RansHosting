<?php
if (!defined('ABSPATH')) {
    exit;
}
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
  <meta charset="<?php bloginfo('charset'); ?>" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="theme-color" content="#faf7f1" />
  <script>
    /* Apply the saved theme before first paint to avoid a flash. */
    (function () {
      try {
        var t = localStorage.getItem('rh-theme');
        if (!t) t = 'light'; /* RansTech brand is light by default */
        document.documentElement.setAttribute('data-theme', t);
      } catch (e) { document.documentElement.setAttribute('data-theme', 'light'); }
    })();
  </script>
  <?php wp_head(); ?>
</head>
<body <?php body_class('ranshosting-body'); ?>>
<?php wp_body_open(); ?>
<a class="rh-skip" href="#rh-main">Skip to content</a>
