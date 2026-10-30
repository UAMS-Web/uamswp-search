<?php
/**
 * Plugin Name: UAMS Search
 * Description: Install-wide database search, PDFs, spelling suggestions, and accessible live search.
 * Version: 0.1.0
 * Author: UAMS Web
 * License: GPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Requires PHP: 8.1
 * Requires at least: 6.4
 * Network: true
 * Text Domain: uams-search
 *
 * Copyright (C) 2026 University of Arkansas for Medical Sciences
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */
defined('ABSPATH') || exit;
define('UAMS_SEARCH_FILE', __FILE__);
define('UAMS_SEARCH_VERSION', '0.1.0');
require_once __DIR__ . '/includes/autoload.php';
require_once __DIR__ . '/includes/helpers.php';
register_activation_hook(__FILE__, ['UAMS_Search\\Plugin', 'activate']);
register_deactivation_hook(__FILE__, ['UAMS_Search\\Plugin', 'deactivate']);
add_action('plugins_loaded', ['UAMS_Search\\Plugin', 'boot'], 20);
