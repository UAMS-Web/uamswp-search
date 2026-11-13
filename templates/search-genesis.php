<?php

/** Search results template for the Genesis framework and the UAMS 2020 theme. */

use UAMS_Search\Integration;

defined('ABSPATH') || exit;
// Replace the legacy template's secondary searches without changing files in the theme.
remove_action('genesis_loop', 'genesis_do_loop');
add_action('genesis_loop', [Integration::class, 'render']);
remove_action('genesis_before_loop', 'genesis_do_search_title');
genesis();
