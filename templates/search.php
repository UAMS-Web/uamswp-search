<?php

use UAMS_Search\Integration;

defined('ABSPATH') || exit;
get_header();
echo '<main id="main-content" class="uams-search-main">';
Integration::render();
echo '</main>';
get_footer();
