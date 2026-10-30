<?php
/**
 * Plugin Name: uams search
 * Description: Install-wide database search, PDFs, spelling suggestions, and accessible live search.
 * Version: 0.1.0
 * Author: UAMS Web
 * License: GPL-3.0-or-later
 * Requires PHP: 8.1
 * Requires at least: 6.4
 * Network: true
 * Text Domain: uams-search
 */
defined('ABSPATH') || exit;
define('UAMS_SEARCH_FILE', __FILE__);
define('UAMS_SEARCH_VERSION', '0.1.0');
require_once __DIR__ . '/includes/autoload.php';
require_once __DIR__ . '/includes/helpers.php';
register_activation_hook(__FILE__, ['UAMS_Search\\Plugin', 'activate']);
register_deactivation_hook(__FILE__, ['UAMS_Search\\Plugin', 'deactivate']);
add_action('plugins_loaded', ['UAMS_Search\\Plugin', 'boot'], 20);
