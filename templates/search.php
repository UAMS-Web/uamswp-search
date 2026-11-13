<?php

/** Search results template for other themes: the header, the results and the footer. */

use UAMS_Search\Integration;

defined('ABSPATH') || exit;
get_header();
echo '<main id="main-content" class="uams-search-main">';
Integration::render();
echo '</main>';
get_footer();
