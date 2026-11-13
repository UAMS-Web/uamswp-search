<?php

namespace UAMS_Search;

use WP_Post;

/** The admin screens, settings forms and AJAX endpoint of the plugin. */
final class Admin
{
    /** The plugin's name in menus and headings. */
    public const NAME = 'UAMSWP Search';

    /** The page slug of the Reports screen. */
    private const REPORTS = 'uams-search-reports';

    /** The page slug of the Settings screen. */
    private const SETTINGS = 'uams-search-settings';

    /** Register the admin menus, form handlers, assets, notices and the PDF exclusion field. */
    public static function hooks(): void
    {
        // Register the menus, form handlers, AJAX endpoint, assets and notices
        add_action('network_admin_menu', [self::class, 'network_menu']);
        add_action('admin_init', [self::class, 'redirect_legacy_network_page']);
        add_action('admin_menu', [self::class, 'site_menus'], 99);
        add_action('admin_post_uams_search_save', [self::class, 'save']);
        add_action('admin_post_uams_search_site', [self::class, 'save_site']);
        add_action('admin_post_uams_search_retry', [self::class, 'retry']);
        add_action('admin_post_uams_search_setup', [self::class, 'setup']);
        add_action('wp_ajax_uams_search_rebuild', [self::class, 'rebuild_ajax']);
        add_action('admin_enqueue_scripts', [self::class, 'rebuild_assets']);
        add_action('admin_enqueue_scripts', [self::class, 'settings_assets']);
        add_action('admin_notices', [self::class, 'notice']);
        add_action('network_admin_notices', [self::class, 'notice']);
        // Add the Exclude From Search checkbox to PDF attachments
        add_filter('attachment_fields_to_edit', static function (mixed $fields, WP_Post $post): mixed {
            if ($post->post_mime_type !== 'application/pdf') {
                return $fields;
            }

            $checked = checked((bool) get_post_meta($post->ID, '_uams_search_exclude', true), true, false);
            $fields['uams_search_exclude'] = ['label' => 'Search Visibility', 'input' => 'html', 'html' => '<input type="hidden" name="attachments['.$post->ID.'][uams_search_present]" value="1"><label><input type="checkbox" name="attachments['.$post->ID.'][uams_search_exclude]" value="1" '.$checked.'> Exclude From Search</label>'];

            return $fields;
        }, 10, 2);
        // Save the checkbox for users who can edit the attachment
        add_filter('attachment_fields_to_save', static function (mixed $post, mixed $attachment): mixed {
            if (! empty($attachment['uams_search_present']) && current_user_can('edit_post', $post['ID'])) {
                update_post_meta($post['ID'], '_uams_search_exclude', empty($attachment['uams_search_exclude']) ? 0 : 1);
            }

            return $post;
        }, 10, 2);
    }

    /**
     * Stop the request with an error message unless the user can manage the plugin's settings.
     */
    private static function capable(): void
    {
        // Refuse users without the settings capability
        if (! current_user_can(is_multisite() ? 'manage_network_options' : 'manage_options')) {
            wp_die('You cannot manage search settings.');
        }
    }

    /**
     * The network admin's own top-level menu. The index screen is its first submenu item, followed by reports and settings.
     */
    public static function network_menu(): void
    {
        add_menu_page(self::NAME, self::NAME, 'manage_network_options', 'uams-search', [self::class, 'page'], 'dashicons-search', 26);
        add_submenu_page('uams-search', self::NAME.' Index', 'Index', 'manage_network_options', 'uams-search', [self::class, 'page']);
        add_submenu_page('uams-search', self::NAME.' Reports', 'Reports', 'manage_network_options', self::REPORTS, [self::class, 'reports_page']);
        add_submenu_page('uams-search', self::NAME.' Settings', 'Settings', 'manage_network_options', self::SETTINGS, [self::class, 'settings_page']);
    }

    /** Add the per-site settings page, and on a single site the index, reports and settings pages too. */
    public static function site_menus(): void
    {
        // Add the site's own search settings page
        add_options_page(self::NAME, self::NAME, 'manage_options', 'uams-search-site', [self::class, 'site_page']);
        // Add the network screens under Settings on a single site
        if (! is_multisite()) {
            add_options_page(self::NAME.' Index', self::NAME.' Index', 'manage_options', 'uams-search', [self::class, 'page']);
            add_options_page(self::NAME.' Reports', self::NAME.' Reports', 'manage_options', self::REPORTS, [self::class, 'reports_page']);
            add_options_page(self::NAME.' Settings', self::NAME.' Settings', 'manage_options', self::SETTINGS, [self::class, 'settings_page']);
        }

        // Use the existing UAMS Settings menu when it is available, without assuming a plugin slug.
        global $menu;
        foreach ($menu as $item) {
            if (strip_tags($item[0]) === 'UAMS Settings') {
                add_submenu_page($item[2], 'Search', 'Search', 'manage_options', 'uams-search-site', [self::class, 'site_page']);
                break;
            }
        }
    }

    /**
     * The network index screen used to live under Settings. Where a link or bookmark still names that address, return the screen's current one.
     *
     * @return string|null The current address, or null when the request is not for the old address or the user cannot manage the network.
     */
    public static function legacy_network_url(): ?string
    {
        // Match only the old address
        global $pagenow;
        if (! is_network_admin() || $pagenow !== 'settings.php' || Integration::param('page') !== 'uams-search' || ! current_user_can('manage_network_options')) {
            return null;
        }

        return self::home();
    }

    /** Redirect the old network index address to the current one. */
    public static function redirect_legacy_network_page(): void
    {
        // Redirect when the request used the old address
        $url = self::legacy_network_url();
        if ($url !== null) {
            wp_safe_redirect($url);
            exit;
        }
    }

    /**
     * The address of the Index screen.
     *
     * @return string The admin URL.
     */
    private static function home(): string
    {
        return self::screen_url('uams-search');
    }

    /**
     * The address of one of the plugin's screens.
     *
     * @param  string  $slug  The page slug.
     * @return string The network admin URL on a multisite, otherwise the Settings page URL.
     */
    private static function screen_url(string $slug): string
    {
        return is_multisite() ? network_admin_url('admin.php?page='.$slug) : admin_url('options-general.php?page='.$slug);
    }

    /** Load the rebuild script and style on the Index screen. */
    public static function rebuild_assets(): void
    {
        // Load only on the Index screen, for users who can manage it
        if (Integration::param('page') !== 'uams-search' || ! current_user_can(is_multisite() ? 'manage_network_options' : 'manage_options')) {
            return;
        }

        wp_enqueue_script('uams-search-rebuild', plugins_url('assets/admin-rebuild.js', UAMS_SEARCH_FILE), [], UAMS_SEARCH_VERSION, true);
        wp_enqueue_style('uams-search-rebuild', plugins_url('assets/admin-rebuild.css', UAMS_SEARCH_FILE), [], UAMS_SEARCH_VERSION);
        wp_localize_script('uams-search-rebuild', 'UAMSRebuild', ['url' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('uams_search_rebuild'), 'job' => Rebuild::state()]);
    }

    /**
     * The Add Row and Remove controls for the field and taxonomy tables belong to the Settings screen only.
     */
    public static function settings_assets(): void
    {
        // Load only on the Settings screen, for users who can manage it
        if (Integration::param('page') !== self::SETTINGS || ! current_user_can(is_multisite() ? 'manage_network_options' : 'manage_options')) {
            return;
        }

        wp_enqueue_script('uams-search-settings', plugins_url('assets/admin-settings.js', UAMS_SEARCH_FILE), [], UAMS_SEARCH_VERSION, true);
    }

    /** Answer a rebuild request from the Index screen with JSON. */
    public static function rebuild_ajax(): void
    {
        // Refuse users who cannot manage the index, and requests that are not POST
        if (! current_user_can(is_multisite() ? 'manage_network_options' : 'manage_options')) {
            wp_send_json_error(['message' => 'You cannot rebuild this search index.'], 403);
        }

        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            wp_send_json_error(['message' => 'Use a POST request.'], 405);
        }

        // Check the nonce and read the request
        check_ajax_referer('uams_search_rebuild', 'nonce');
        $op = isset($_POST['op']) && is_scalar($_POST['op']) ? sanitize_key(wp_unslash((string) $_POST['op'])) : '';
        $id = isset($_POST['job_id']) && is_scalar($_POST['job_id']) ? sanitize_text_field(wp_unslash((string) $_POST['job_id'])) : '';
        $revision = isset($_POST['revision']) && is_scalar($_POST['revision']) ? absint($_POST['revision']) : 0;
        // Run the requested action
        try {
            $result = match ($op) {
                'start' => Rebuild::start(($_POST['include_noindex'] ?? '') === '1'),
                'step' => Rebuild::step($id, $revision),
                'pause' => Rebuild::pause($id),
                'cancel' => Rebuild::cancel($id),
                'status' => ['job' => Rebuild::state(), 'busy' => false],
                default => throw new \InvalidArgumentException('Unknown rebuild action.'),
            };
        } catch (\Throwable $throwable) {
            wp_send_json_error(['message' => $throwable->getMessage()], $throwable instanceof \InvalidArgumentException ? 400 : 500);
        }

        // Send the result
        wp_send_json_success($result);
    }

    /** Show the setup, rebuild-needed and rebuild-in-progress notices to users who manage the index. */
    public static function notice(): void
    {
        // Show notices only to users who manage the index
        if (! current_user_can(is_multisite() ? 'manage_network_options' : 'manage_options')) {
            return;
        }

        // Warn when the tables are missing or a rebuild is needed
        if (! DB::ready()) {
            echo '<div class="notice notice-warning"><p>UAMSWP Search needs its index tables. <a href="'.esc_url(self::home()).'">Create them on the Index screen</a>.</p></div>';
        } elseif (Config::needs_rebuild()) {
            echo '<div class="notice notice-warning"><p>Some UAMSWP Search sites need a rebuild. Rebuild every site before comparing or switching search engines.</p></div>';
        }

        // Tell the user a rebuild is in progress
        if (DB::ready() && Rebuild::active()) {
            echo '<div class="notice notice-info"><p>A browser rebuild is in progress or paused. Saved edits remain queued until it finishes or is canceled. <a href="'.esc_url(self::home().'#uams-search-rebuild').'">Resume or cancel the rebuild</a>.</p></div>';
        }
    }

    /** Create the index tables, then return to the Index screen. */
    public static function setup(): void
    {
        // Check the user and the nonce
        self::capable();
        check_admin_referer('uams_search_setup');
        // Create the tables
        try {
            DB::install();
        } catch (\Throwable $throwable) {
            wp_die(esc_html($throwable->getMessage()));
        }

        // Return to the Index screen
        wp_safe_redirect(self::home());
        exit;
    }

    /** Save the network settings form, then return to the Settings screen. */
    public static function save(): void
    {
        // Check the user and the nonce, and read the submitted settings
        self::capable();
        check_admin_referer('uams_search_save');
        $input = isset($_POST['settings']) && is_array($_POST['settings']) ? wp_unslash($_POST['settings']) : [];
        $c = Config::get();
        // Save the page size, the switches and the chosen sites
        $c['results_per_page'] = Config::page_size($input['results_per_page'] ?? 20);
        foreach (['enabled', 'live', 'logging', 'pdf', 'pdf_parent'] as $key) {
            $c[$key] = ! empty($input[$key]);
        }

        $c['sites'] = array_values(array_intersect(Config::public_sites(), array_map(intval(...), (array) ($input['sites'] ?? []))));
        // Save the source weights
        foreach (array_keys(Config::defaults()['weights']) as $key) {
            $c['weights'][$key] = self::number($input['weights'][$key] ?? 0);
        }

        // Read the chosen types with their boosts, field weights and taxonomy weights
        $types = [];
        $fields = [];
        $taxonomies = [];
        foreach ((array) ($input['types'] ?? []) as $name => $values) {
            $name = sanitize_key($name);
            if (! $name || ! is_array($values) || empty($values['include'])) {
                continue;
            }

            $types[$name] = self::number($values['boost'] ?? 1);
            foreach (['fields', 'taxonomies'] as $group) {
                $parsed = [];
                foreach ((array) ($values[$group] ?? []) as $row) {
                    if (! is_array($row)) {
                        continue;
                    }

                    $key = sanitize_key(is_scalar($row['name'] ?? '') ? $row['name'] : '');
                    if ($key) {
                        $parsed[$key] = self::number($row['weight'] ?? 1);
                    }
                }

                if ($group === 'fields') {
                    $fields[$name] = $parsed;
                } else {
                    $taxonomies[$name] = $parsed;
                }
            }
        }

        // Require at least one type
        if ($types === []) {
            wp_die('Choose at least one post type.');
        }

        // Save the types, stop words and synonyms
        $c['types'] = $types;
        $c['fields'] = $fields;
        $c['taxonomies'] = $taxonomies;
        $stopwords = $input['stopwords'] ?? '';
        $c['stopwords'] = Text::words(is_scalar($stopwords) ? (string) $stopwords : '');
        // Read the synonym rules: one word, an equals sign and up to ten alternates per line
        $c['synonyms'] = [];
        $lines = is_scalar($input['synonyms'] ?? '') ? (string) $input['synonyms'] : '';
        foreach (preg_split('/\R/', $lines) as $line) {
            $parts = explode('=', $line, 2);
            if (count($parts) !== 2) {
                continue;
            }

            $key = Text::words($parts[0]);
            if (count($key) !== 1) {
                continue;
            }

            $alternates = [];
            foreach (explode(',', $parts[1]) as $value) {
                $value = implode(' ', Text::words($value));
                if ($value !== '') {
                    $alternates[] = mb_substr($value, 0, 100);
                }
            }

            $c['synonyms'][$key[0]] = array_slice(array_unique($alternates), 0, 10);
        }

        // Save the PDF limits
        $c['pdf_max_mb'] = max(1, min(100, self::number($input['pdf_max_mb'] ?? 20)));
        $c['pdf_timeout'] = max(1, min(120, (int) ($input['pdf_timeout'] ?? 30)));
        // Refuse to enable search before the index exists
        if ($c['enabled'] && ! DB::ready()) {
            wp_die('Create and build the index before enabling search.');
        }

        // Save and return to the Settings screen
        update_site_option('uams_search_settings', $c);
        wp_safe_redirect(self::screen_url(self::SETTINGS));
        exit;
    }

    /**
     * A weight from form input.
     *
     * @param  mixed  $value  The submitted value.
     * @return float The number limited to 0 to 100, or 0 when it is not numeric.
     */
    private static function number(mixed $value): float
    {
        return is_scalar($value) && is_numeric($value) && is_finite((float) $value) ? max(0, min(100, (float) $value)) : 0;
    }

    /** Print the per-site search settings form. */
    public static function site_page(): void
    {
        // Refuse users who cannot manage the site
        if (! current_user_can('manage_options')) {
            wp_die('You cannot manage this site.');
        }

        // Print the heading, then the default scope options
        echo '<div class="wrap"><h1>Search Settings</h1><form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="uams_search_site">';
        wp_nonce_field('uams_search_site');
        echo '<fieldset><legend><h2>Default Search Scope</h2></legend>';
        // The same options and labels as the results page, whether or not Google results are switched on.
        $options = Config::scope_options(false);
        $saved = get_option('uams_search_scope', 'site');
        $current = is_string($saved) && isset($options[$saved]) ? $saved : array_key_first($options);
        foreach ($options as $value => $label) {
            echo '<p><label><input type="radio" name="scope" value="'.esc_attr($value).'" '.checked($current, $value, false).'> '.esc_html($label).'</label></p>';
        }

        // Print the Google results switch and the save button
        echo '</fieldset><p><label><input type="checkbox" name="google" value="1" '.checked(Config::option_flag('uams_search_google', true), true, false).'> Show the Existing All of UAMS Google Search</label></p>';
        submit_button('Save search settings');
        echo '</form></div>';
    }

    /** Save the per-site settings, then return to the page. */
    public static function save_site(): void
    {
        // Check the user and the nonce
        if (! current_user_can('manage_options')) {
            wp_die('You cannot manage this site.');
        }

        check_admin_referer('uams_search_site');
        // Save the scope and the Google switch
        $options = array_keys(Config::scope_options(false));
        $scope = is_scalar($_POST['scope'] ?? '') ? sanitize_text_field(wp_unslash($_POST['scope'])) : '';
        update_option('uams_search_scope', in_array($scope, $options, true) ? $scope : $options[0]);
        update_option('uams_search_google', ! empty($_POST['google']));
        // Return to the page
        wp_safe_redirect(admin_url('options-general.php?page=uams-search-site'));
        exit;
    }

    /** Queue a failed item for indexing again, then return to the failed items list. */
    public static function retry(): void
    {
        // Check the user, the nonce, and the site and item
        self::capable();
        check_admin_referer('uams_search_retry');
        $site = absint($_GET['site'] ?? 0);
        $id = absint($_GET['object'] ?? 0);
        if (! in_array($site, Config::public_sites(), true) || ! $id) {
            wp_die('Invalid site or item.');
        }

        // Revisions and autosaves are site-local; check them in the target site's context.
        $changed = get_current_blog_id() !== $site;
        if ($changed) {
            switch_to_blog($site);
        }

        // Queue the item in its own site
        try {
            Queue::push($id, 'upsert', $site);
        } finally {
            // Switch back to the earlier site
            if ($changed) {
                restore_current_blog();
            }
        }

        // Return to the failed items list
        wp_safe_redirect(self::screen_url(self::REPORTS).'#failed-items');
        exit;
    }

    /**
     * Opens a network screen: the capability check, its own heading, and the table setup form in place of the screen when the index tables do not exist yet.
     *
     * @param  string  $heading  The screen's heading.
     * @param  string  $intro  Trusted HTML to print under the heading.
     * @return bool Whether the screen can show its content.
     */
    private static function open(string $heading, string $intro = ''): bool
    {
        // Check the user and print the heading
        self::capable();
        echo '<div class="wrap"><h1>'.esc_html($heading).'</h1>'.$intro;
        // Continue when the tables exist
        if (DB::ready()) {
            return true;
        }

        // Offer to create the tables instead
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="uams_search_setup">';
        wp_nonce_field('uams_search_setup');
        submit_button('Create index tables');
        echo '</form></div>';

        return false;
    }

    /** Print the Index screen: the rebuild controls and the index status. */
    public static function page(): void
    {
        // Print the heading, or the setup form when the tables are missing
        if (! self::open(self::NAME.' Index', '<p>Build and compare the index before enabling it. While disabled, signed-in users can preview with <code>?s=heart&amp;engine=uams</code>.</p>')) {
            return;
        }

        // Print the rebuild controls and the status
        self::rebuild_controls();
        self::status();
        echo '</div>';
    }

    /** Print the Reports screen: failed items and searches with no results. */
    public static function reports_page(): void
    {
        // Print the heading, or the setup form when the tables are missing
        if (! self::open(self::NAME.' Reports')) {
            return;
        }

        // Print the reports
        self::reports();
        echo '</div>';
    }

    /** Print the Settings screen. */
    public static function settings_page(): void
    {
        // Print the heading, or the setup form when the tables are missing
        if (! self::open(self::NAME.' Settings')) {
            return;
        }

        // Print the switches
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="uams_search_save">';
        wp_nonce_field('uams_search_save');
        foreach (['enabled' => 'Use UAMSWP Search for Visitors', 'live' => 'Show Live Search Suggestions', 'logging' => 'Keep Search Text With No Results for up to 90 Days', 'pdf' => 'Index PDFs', 'pdf_parent' => 'Link PDFs to Their Parent Page When Available'] as $key => $label) {
            echo '<p><label><input type="checkbox" name="settings['.esc_attr($key).']" value="1" '.checked(Config::flag($key), true, false).'> '.esc_html($label).'</label></p>';
        }

        // Print the page size and the sites searched together
        echo '<p><label>Results per Page <input type="number" name="settings[results_per_page]" min="1" max="100" step="1" value="'.Config::page_size().'"></label><br>Default: 20. Choose 1 to 100. Changes apply without rebuilding the index.</p>';
        echo '<fieldset><legend><h2>Sub-Sites in Whole-Website Search</h2></legend>';
        foreach (Config::public_sites() as $site) {
            $label = is_multisite() ? get_blog_details($site)->blogname.' (#'.$site.')' : get_bloginfo('name');
            echo '<p><label><input type="checkbox" name="settings[sites][]" value="'.(int) $site.'" '.checked(Config::chosen_sites() === null || in_array($site, Config::chosen_sites(), true), true, false).'> '.esc_html($label).'</label></p>';
        }

        // Print the source weights
        echo '</fieldset><fieldset><legend><h2>Source Weights</h2></legend>';
        echo '<table class="form-table" role="presentation"><tbody>';
        foreach (Config::section('weights') as $key => $weight) {
            self::numeric('settings[weights]['.$key.']', $key === 'pdf' ? 'PDF' : ucwords(str_replace('_', ' ', (string) $key)), $weight);
        }

        // Print each content type with its boost and its field and taxonomy tables
        echo '</tbody></table></fieldset><h2>Content To Index</h2><p>Field and taxonomy weights multiply the source weight. Blank rows are ignored. Rebuild all sites after changing indexed content or stop words.</p>';
        $types = array_unique(array_merge(array_keys(Config::section('types')), array_keys(get_post_types(['public' => true]))));
        foreach ($types as $type) {
            $object = get_post_type_object($type);
            $name = $object ? $object->labels->name : ucfirst($type);
            echo '<fieldset style="padding:1rem;border:1px solid #aaa;margin-block:1rem"><legend><strong>'.esc_html($name.' ('.$type.')').'</strong></legend>';
            echo '<p><label><input type="checkbox" name="settings[types]['.esc_attr($type).'][include]" value="1" '.checked(isset(Config::section('types')[$type]), true, false).'> Include This Content Type</label></p>';
            echo '<table class="form-table" role="presentation"><tbody>';
            self::numeric('settings[types]['.$type.'][boost]', 'Type Boost', Config::section('types')[$type] ?? 1);
            echo '</tbody></table>';
            foreach (['fields' => 'ACF Fields', 'taxonomies' => 'Taxonomies'] as $group => $label) {
                $rows = array_map(static fn (mixed $k, mixed $v): array => ['name' => (string) $k, 'weight' => $v], array_keys(Config::entry($group, $type)), array_values(Config::entry($group, $type)));
                // One blank row is always offered, so a new field needs no JavaScript; the Add Row button adds more.
                $rows[] = ['name' => '', 'weight' => 1];
                $size = max(28, ...array_map(static fn (array $row): int => mb_strlen($row['name']) + 2, $rows));
                $prefix = 'settings[types]['.$type.']['.$group.']';
                $what = $name.' '.$label;
                echo '<h3>'.esc_html($label).'</h3><table class="uams-search-rows" data-prefix="'.esc_attr($prefix).'" data-label="'.esc_attr($what).'" data-size="'.$size.'"><thead><tr><th scope="col">Name</th><th scope="col">Weight</th><th scope="col"><span class="screen-reader-text">Remove</span></th></tr></thead><tbody>';
                foreach ($rows as $i => $row) {
                    self::field_row($prefix, $what, $i, $row, $size);
                }

                echo '</tbody></table><p><button type="button" class="button uams-add-row" aria-label="'.esc_attr('Add Row to '.$what).'" hidden>Add Row</button></p>';
            }

            echo '</fieldset>';
        }

        // Print the stop words and the synonym rules
        echo '<p><label for="uams-stopwords">Extra Stop Words, Separated by Spaces</label><br><textarea id="uams-stopwords" name="settings[stopwords]" rows="3" cols="70">'.esc_textarea(implode(' ', Config::section('stopwords'))).'</textarea></p>';
        $synonyms = [];
        foreach (Config::section('synonyms') as $key => $values) {
            $synonyms[] = $key.' = '.implode(', ', $values);
        }

        echo '<p><label for="uams-synonyms">Synonyms, One Rule per Line, Such as heart = cardiac, cardiology</label><br><textarea id="uams-synonyms" name="settings[synonyms]" rows="5" cols="70">'.esc_textarea(implode("\n", $synonyms)).'</textarea></p>';
        // Print the PDF limits and the save button
        echo '<table class="form-table" role="presentation"><tbody>';
        self::numeric('settings[pdf_max_mb]', 'Largest PDF, MB', Config::number('pdf_max_mb'));
        self::numeric('settings[pdf_timeout]', 'PDF Time Limit, Seconds', Config::number('pdf_timeout'), 120);
        echo '</tbody></table>';
        submit_button('Save network settings');
        echo '</form></div>';
    }

    /** Print the rebuild controls, which assets/admin-rebuild.js drives. */
    public static function rebuild_controls(): void
    {
        // Check the no-index box when only no-index sites exist or the setting is on
        $include = Config::flag('preview_noindex') || (! Config::site_ids(false) && Config::site_ids(true));
        echo '<section id="uams-search-rebuild" aria-labelledby="uams-rebuild-heading"><h2 id="uams-rebuild-heading">Rebuild Index</h2>';
        echo '<p>Build the full index without command-line access. Keep this page open while it runs. If you leave, come back and choose Resume rebuild.</p>';
        echo '<p>Existing results stay available. Each site switches to its new index when its content is ready. Saved edits wait in the queue until the rebuild finishes or is canceled.</p>';
        echo '<p><label><input id="uams-rebuild-noindex" type="checkbox" '.checked($include, true, false).'> Include No-Index Sites for Signed-In Previews</label><br><span class="description">This does not change search-engine visibility. No-index sites stay excluded from signed-out search results.</span></p>';
        echo '<p><button type="button" id="uams-rebuild-start" class="button button-primary">Rebuild index</button> <button type="button" id="uams-rebuild-resume" class="button button-primary" hidden>Resume rebuild</button> <button type="button" id="uams-rebuild-pause" class="button" hidden>Pause rebuild</button> <button type="button" id="uams-rebuild-cancel" class="button" hidden>Cancel rebuild</button></p>';
        echo '<progress id="uams-rebuild-progress" aria-label="Index rebuild progress" value="0" max="100"></progress>';
        echo '<p id="uams-rebuild-status" role="status" aria-live="polite" aria-atomic="true">Ready to rebuild.</p><p id="uams-rebuild-counts"></p><p id="uams-rebuild-error" role="alert" hidden></p>';
        echo '<noscript><p>JavaScript is required for the browser rebuild. Enable it, then reload this page.</p></noscript></section>';
    }

    /**
     * One Name and Weight row of a field or taxonomy table. The Remove button stays hidden until the script that makes it work has loaded.
     *
     * @param  string  $prefix  The input name prefix of the table.
     * @param  string  $what  The table's label, used in accessible names.
     * @param  int  $i  The row's position.
     * @param  array{name: string, weight: mixed}  $row  The row's values.
     * @param  int  $size  The width of the name input.
     */
    private static function field_row(string $prefix, string $what, int $i, array $row, int $size): void
    {
        $key = $prefix.'['.$i.']';
        echo '<tr><td><input size="'.$size.'" aria-label="'.esc_attr($what.' name '.($i + 1)).'" name="'.esc_attr($key.'[name]').'" value="'.esc_attr($row['name']).'"></td><td><input type="number" min="0" max="100" step="0.1" aria-label="'.esc_attr($what.' weight '.($i + 1)).'" name="'.esc_attr($key.'[weight]').'" value="'.esc_attr($row['weight']).'"></td><td><button type="button" class="button uams-remove-row" aria-label="'.esc_attr('Remove '.$what.' row '.($i + 1)).'" hidden>Remove</button></td></tr>';
    }

    /**
     * Print one labeled number field as a table row.
     *
     * @param  string  $name  The input name.
     * @param  string  $label  The visible label.
     * @param  mixed  $value  The current value.
     * @param  int  $max  The largest value allowed.
     */
    private static function numeric(string $name, string $label, mixed $value, int $max = 100): void
    {
        // Derive the input ID from the name
        $id = 'uams-'.trim((string) preg_replace('/[^a-z0-9]+/i', '-', $name), '-');
        echo '<tr><th scope="row"><label for="'.esc_attr($id).'">'.esc_html($label).'</label></th><td><input id="'.esc_attr($id).'" type="number" min="0" max="'.$max.'" step="0.1" style="width:6em" name="'.esc_attr($name).'" value="'.esc_attr($value).'"></td></tr>';
    }

    /** Print the queue length, the last queue run and the index counts. */
    private static function status(): void
    {
        global $wpdb;
        // Print the queue length and the last run
        echo '<section id="status"><h2>Index Status</h2><p>Queue length: '.(int) $wpdb->get_var('SELECT COUNT(*) FROM `'.DB::table('queue').'`').'. Last queue run: '.esc_html(Time::local(get_site_option('uams_search_last_queue', ''))).'.</p>';
        // Print the counts by site, type and status
        $rows = $wpdb->get_results('SELECT site_id,post_type,status,COUNT(*) AS total FROM `'.DB::table().'` GROUP BY site_id,post_type,status ORDER BY site_id,post_type', ARRAY_A);
        self::table(['Site Name', 'Site ID', 'Post type', 'Status', 'Count'], SiteNames::prefix(array_map(array_values(...), $rows)));
        echo '</section>';
    }

    /** Print the failed items and the searches that returned no results. */
    private static function reports(): void
    {
        global $wpdb;
        // Read the latest failed and textless items
        echo '<section id="failed-items"><h2>Failed Items and PDFs Without Text</h2><p>Showing the latest 100 items. PDFs without text still match their titles and filenames.</p>';
        $rows = $wpdb->get_results('SELECT site_id,object_id,status,error FROM `'.DB::table()."` WHERE status IN ('error','scanned') ORDER BY indexed_at DESC LIMIT 100", ARRAY_A);
        // Print each item with a retry link
        echo '<table class="widefat striped"><thead><tr><th scope="col">Site Name</th><th scope="col">Site ID</th><th scope="col">Item</th><th scope="col">Status</th><th scope="col">Message</th><th scope="col">Action</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $url = wp_nonce_url(add_query_arg(['action' => 'uams_search_retry', 'site' => $row['site_id'], 'object' => $row['object_id']], admin_url('admin-post.php')), 'uams_search_retry');
            echo '<tr>';
            foreach (SiteNames::prefix([array_values($row)])[0] as $value) {
                echo '<td>'.esc_html($value).'</td>';
            } echo '<td><a href="'.esc_url($url).'">Retry item '.(int) $row['object_id'].' on site '.(int) $row['site_id'].'</a></td></tr>';
        }

        // Print the searches with no results
        echo '</tbody></table></section><section id="no-results"><h2>Searches With No Results, Last 30 Days</h2><p>Search text can include health terms. No IP address or user ID is stored. Entries are deleted after 90 days.</p>';
        $rows = $wpdb->get_results('SELECT site_id,search_text,SUM(count) AS total FROM `'.DB::table('misses').'` WHERE day>=UTC_DATE()-INTERVAL 30 DAY GROUP BY site_id,search_hash,search_text ORDER BY total DESC LIMIT 100', ARRAY_A);
        self::table(['Site Name', 'Site ID', 'Search', 'Count'], SiteNames::prefix(array_map(array_values(...), $rows)));
        echo '</section>';
    }

    /**
     * Print a table.
     *
     * @param  array<int, string>  $heads  The column headings.
     * @param  array<int, array<int, mixed>>  $rows  The cell values of each row.
     */
    private static function table(array $heads, array $rows): void
    {
        // Print the headings, then the rows
        echo '<table class="widefat striped"><thead><tr>';
        foreach ($heads as $head) {
            echo '<th scope="col">'.esc_html($head).'</th>';
        } echo '</tr></thead><tbody>';
        foreach ($rows as $row) {
            echo '<tr>';
            foreach ($row as $value) {
                echo '<td>'.esc_html($value).'</td>';
            } echo '</tr>';
        } echo '</tbody></table>';
    }
}
