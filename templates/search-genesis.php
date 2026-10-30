<?php
defined('ABSPATH') || exit;
// Replace the legacy template's secondary searches without changing files in the theme.
remove_action('genesis_loop', 'genesis_do_loop');
add_action('genesis_loop', ['UAMS_Search\\Integration', 'render']);
remove_action('genesis_before_loop', 'genesis_do_search_title');
genesis();
