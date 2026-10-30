<?php
defined('ABSPATH') || exit;
get_header();
echo '<main id="main-content" class="uams-search-main">';
\UAMS_Search\Integration::render();
echo '</main>';
get_footer();
