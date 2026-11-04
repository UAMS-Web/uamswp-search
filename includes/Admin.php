<?php
namespace UAMS_Search;

final class Admin {
    public static function hooks(): void {
        add_action('network_admin_menu', static function () {
            add_submenu_page('settings.php', 'uams search', 'uams search', 'manage_network_options', 'uams-search', [self::class, 'page']);
        });
        add_action('admin_menu', static function () {
            global $menu;
            // One page per site with tabs (Settings, Featured results). It sits under UAMS Settings
            // when that menu exists, otherwise under Settings.
            $under_uams = false;
            foreach ($menu as $item) { if (strip_tags($item[0]) === 'UAMS Settings') { $under_uams = true; } }
            if (!$under_uams) { add_options_page('Search', 'Search', 'manage_options', 'uams-search-site', [self::class, 'site_page']); }
            if (!is_multisite()) { add_options_page('uams search index', 'uams search index', 'manage_options', 'uams-search', [self::class, 'page']); }
            // Use the existing UAMS Settings menu when it is available, without assuming a plugin slug.
            foreach ($menu as $item) {
                if (strip_tags($item[0]) === 'UAMS Settings') { add_submenu_page($item[2], 'Search', 'Search', 'manage_options', 'uams-search-site', [self::class, 'site_page']); break; }
            }
        }, 99);
        add_action('admin_post_uams_search_save', [self::class, 'save']);
        add_action('admin_post_uams_search_site', [self::class, 'save_site']);
        add_action('admin_post_uams_search_retry', [self::class, 'retry']);
        add_action('admin_post_uams_search_setup', [self::class, 'setup']);
        add_action('admin_post_uams_search_featured', [self::class, 'featured_save']);
        add_action('admin_post_uams_search_featured_action', [self::class, 'featured_action']);
        add_action('wp_ajax_uams_search_rebuild', [self::class, 'rebuild_ajax']);
        add_action('admin_enqueue_scripts', [self::class, 'rebuild_assets']);
        add_action('admin_notices', [self::class, 'notice']);
        add_action('network_admin_notices', [self::class, 'notice']);
        add_filter('attachment_fields_to_edit', static function ($fields, $post) {
            if ($post->post_mime_type !== 'application/pdf') { return $fields; }
            $checked = checked((bool)get_post_meta($post->ID, '_uams_search_exclude', true), true, false);
            $fields['uams_search_exclude'] = ['label' => 'Search visibility', 'input' => 'html', 'html' => '<input type="hidden" name="attachments[' . (int)$post->ID . '][uams_search_present]" value="1"><label><input type="checkbox" name="attachments[' . (int)$post->ID . '][uams_search_exclude]" value="1" ' . $checked . '> Exclude from search</label>'];
            return $fields;
        }, 10, 2);
        add_filter('attachment_fields_to_save', static function ($post, $attachment) {
            if (!empty($attachment['uams_search_present']) && current_user_can('edit_post', $post['ID'])) { update_post_meta($post['ID'], '_uams_search_exclude', empty($attachment['uams_search_exclude']) ? 0 : 1); }
            return $post;
        }, 10, 2);
    }
    private static function capable(): void {
        if (!current_user_can(is_multisite() ? 'manage_network_options' : 'manage_options')) { wp_die('You cannot manage search settings.'); }
    }
    private static function home(string $tab = ''): string {
        $url = is_multisite() ? network_admin_url('settings.php?page=uams-search') : admin_url('options-general.php?page=uams-search');
        return $tab !== '' ? add_query_arg('tab', $tab, $url) : $url;
    }
    private static function site_home(string $tab = ''): string {
        $url = admin_url('admin.php?page=uams-search-site');
        return $tab !== '' ? add_query_arg('tab', $tab, $url) : $url;
    }
    /** Network page tabs. Settings tabs each save only their own fields. */
    private const NETWORK_TABS = ['index' => 'Index', 'general' => 'General', 'content' => 'Content', 'types' => 'Result types', 'featured' => 'Featured results'];
    private const SITE_TABS = ['settings' => 'Settings', 'featured' => 'Featured results'];
    private static function current_tab(array $tabs): string {
        $tab = Integration::param('tab');
        return isset($tabs[$tab]) ? $tab : (string)array_key_first($tabs);
    }
    /** Tabs are links that reload the page; the current one has aria-current. */
    private static function tabs(callable $url, array $tabs, string $current): void {
        echo '<nav class="nav-tab-wrapper wp-clearfix" aria-label="Search settings sections">';
        foreach ($tabs as $key => $label) {
            $active = $key === $current;
            echo '<a href="' . esc_url($url($key)) . '" class="nav-tab' . ($active ? ' nav-tab-active' : '') . '"' . ($active ? ' aria-current="page"' : '') . '>' . esc_html($label) . '</a>';
        }
        echo '</nav>';
    }
    private static function saved_notice(): void {
        if (Integration::param('notice') === 'settings') { echo '<div class="notice notice-success" role="status"><p>Settings saved.</p></div>'; }
    }
    public static function rebuild_assets(): void {
        if (in_array(Integration::param('page'), ['uams-search', 'uams-search-site'], true)) { wp_enqueue_style('uams-search-admin', plugins_url('assets/admin.css', UAMS_SEARCH_FILE), [], UAMS_SEARCH_VERSION); }
        if (Integration::param('page') !== 'uams-search' || self::current_tab(self::NETWORK_TABS) !== 'index' || !current_user_can(is_multisite() ? 'manage_network_options' : 'manage_options')) { return; }
        wp_enqueue_script('uams-search-rebuild', plugins_url('assets/admin-rebuild.js', UAMS_SEARCH_FILE), [], UAMS_SEARCH_VERSION, true);
        wp_enqueue_style('uams-search-rebuild', plugins_url('assets/admin-rebuild.css', UAMS_SEARCH_FILE), [], UAMS_SEARCH_VERSION);
        wp_localize_script('uams-search-rebuild', 'UAMSRebuild', ['url' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('uams_search_rebuild'), 'job' => Rebuild::state()]);
    }
    public static function rebuild_ajax(): void {
        if (!current_user_can(is_multisite() ? 'manage_network_options' : 'manage_options')) { wp_send_json_error(['message' => 'You cannot rebuild this search index.'], 403); }
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { wp_send_json_error(['message' => 'Use a POST request.'], 405); }
        check_ajax_referer('uams_search_rebuild', 'nonce');
        $op = isset($_POST['op']) && is_scalar($_POST['op']) ? sanitize_key(wp_unslash((string)$_POST['op'])) : '';
        $id = isset($_POST['job_id']) && is_scalar($_POST['job_id']) ? sanitize_text_field(wp_unslash((string)$_POST['job_id'])) : '';
        $revision = isset($_POST['revision']) && is_scalar($_POST['revision']) ? absint($_POST['revision']) : 0;
        try {
            $result = match ($op) {
                'start' => Rebuild::start(($_POST['include_noindex'] ?? '') === '1'),
                'step' => Rebuild::step($id, $revision),
                'pause' => Rebuild::pause($id),
                'cancel' => Rebuild::cancel($id),
                'status' => ['job' => Rebuild::state(), 'busy' => false],
                default => throw new \InvalidArgumentException('Unknown rebuild action.'),
            };
        } catch (\Throwable $e) { wp_send_json_error(['message' => $e->getMessage()], $e instanceof \InvalidArgumentException ? 400 : 500); }
        wp_send_json_success($result);
    }
    public static function notice(): void {
        if (!current_user_can(is_multisite() ? 'manage_network_options' : 'manage_options')) { return; }
        if (!DB::ready()) { echo '<div class="notice notice-warning"><p>uams search needs its index tables. <a href="' . esc_url(self::home()) . '">Open search settings</a>.</p></div>'; }
        elseif (Config::needs_rebuild()) { echo '<div class="notice notice-warning"><p>Some uams search sites need a rebuild. Rebuild every site before comparing or switching search engines.</p></div>'; }
        if (DB::ready() && Rebuild::active()) { echo '<div class="notice notice-info"><p>A browser rebuild is in progress or paused. Saved edits remain queued until it finishes or is cancelled. <a href="' . esc_url(self::home('index') . '#uams-search-rebuild') . '">Resume or cancel the rebuild</a>.</p></div>'; }
    }
    public static function setup(): void {
        self::capable(); check_admin_referer('uams_search_setup');
        try { DB::install(); } catch (\Throwable $e) { wp_die(esc_html($e->getMessage())); }
        wp_safe_redirect(self::home()); exit;
    }
    public static function save(): void {
        self::capable(); check_admin_referer('uams_search_save');
        $input = isset($_POST['settings']) && is_array($_POST['settings']) ? wp_unslash($_POST['settings']) : [];
        $c = Config::get();
        // Each settings tab posts only its own fields; leave the others as saved.
        $section = isset($_POST['section']) && in_array($_POST['section'], ['general', 'content', 'types'], true) ? (string)$_POST['section'] : '';
        if ($section === '') { wp_die('Unknown settings section.'); }
        if ($section === 'general') {
        foreach (['enabled','live','logging','pdf','pdf_parent'] as $key) { $c[$key] = !empty($input[$key]); }
        $c['sites'] = array_values(array_intersect(Config::public_sites(), array_map('intval', (array)($input['sites'] ?? []))));
        $c['featured_max'] = max(1, min(Featured::MAX_ITEMS, (int)($input['featured_max'] ?? 3)));
        $c['pdf_max_mb'] = max(1, min(100, self::number($input['pdf_max_mb'] ?? 20)));
        $c['pdf_timeout'] = max(1, min(120, (int)($input['pdf_timeout'] ?? 30)));
        }
        if ($section === 'content') {
        foreach (array_keys(Config::defaults()['weights']) as $key) { $c['weights'][$key] = self::number($input['weights'][$key] ?? 0); }
        $types = []; $fields = []; $taxonomies = [];
        foreach ((array)($input['types'] ?? []) as $name => $values) {
            $name = sanitize_key($name); if (!$name || !is_array($values) || empty($values['include'])) { continue; }
            $types[$name] = self::number($values['boost'] ?? 1);
            foreach (['fields','taxonomies'] as $group) {
                $parsed = [];
                foreach ((array)($values[$group] ?? []) as $row) {
                    if (!is_array($row)) { continue; }
                    $key = sanitize_key(is_scalar($row['name'] ?? '') ? $row['name'] : '');
                    if ($key) { $parsed[$key] = self::number($row['weight'] ?? 1); }
                }
                if ($group === 'fields') { $fields[$name] = $parsed; } else { $taxonomies[$name] = $parsed; }
            }
        }
        if (!$types) { wp_die('Choose at least one post type.'); }
        $c['types'] = $types; $c['fields'] = $fields; $c['taxonomies'] = $taxonomies;
        $c['stopwords'] = Text::words(is_scalar($input['stopwords'] ?? '') ? $input['stopwords'] : '');
        $c['synonyms'] = [];
        $lines = is_scalar($input['synonyms'] ?? '') ? (string)$input['synonyms'] : '';
        foreach (preg_split('/\R/', $lines) as $line) {
            $parts = explode('=', $line, 2); if (count($parts) !== 2) { continue; }
            $key = Text::words($parts[0]); if (count($key) !== 1) { continue; }
            $alternates = [];
            foreach (explode(',', $parts[1]) as $value) { $value = implode(' ', Text::words($value)); if ($value !== '') { $alternates[] = mb_substr($value, 0, 100); } }
            $c['synonyms'][$key[0]] = array_slice(array_unique($alternates), 0, 10);
        }
        }
        if ($section === 'types') {
        $c['groups'] = []; $rows = [];
        foreach ((array)($input['groups'] ?? []) as $row) {
            if (!is_array($row)) { continue; }
            $label = sanitize_text_field(is_scalar($row['label'] ?? '') ? (string)$row['label'] : '');
            $group_types = array_values(array_filter(array_map('sanitize_key', explode(',', is_scalar($row['types'] ?? '') ? (string)$row['types'] : ''))));
            $slug = sanitize_key(is_scalar($row['slug'] ?? '') ? (string)$row['slug'] : '') ?: sanitize_title($label);
            if ($label === '' || !$group_types || !$slug) { continue; }
            $rows[] = ['order' => (int)($row['order'] ?? 99), 'slug' => $slug, 'label' => mb_substr($label, 0, 60), 'types' => $group_types];
        }
        usort($rows, static fn($x, $y) => $x['order'] <=> $y['order']);
        foreach ($rows as $row) { if (!isset($c['groups'][$row['slug']])) { $c['groups'][$row['slug']] = ['label' => $row['label'], 'types' => $row['types']]; } }
        if (!$c['groups']) { $c['groups'] = Config::defaults()['groups']; }
        }
        if ($c['enabled'] && !DB::ready()) { wp_die('Create and build the index before enabling search.'); }
        update_site_option('uams_search_settings', $c);
        wp_safe_redirect(add_query_arg('notice', 'settings', self::home($section))); exit;
    }
    private static function number($value): float { return is_scalar($value) && is_numeric($value) && is_finite((float)$value) ? max(0, min(100, (float)$value)) : 0; }
    public static function site_page(): void {
        if (!current_user_can('manage_options')) { wp_die('You cannot manage this site.'); }
        $tab = self::current_tab(self::SITE_TABS);
        echo '<div class="wrap uams-admin"><h1>Search</h1>';
        self::tabs([self::class, 'site_home'], self::SITE_TABS, $tab);
        if ($tab === 'featured') { self::featured_section('site'); echo '</div>'; return; }
        self::saved_notice();
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="uams_search_site">';
        wp_nonce_field('uams_search_site');
        echo '<fieldset><legend><h2>Default search scope</h2></legend>';
        foreach (['site' => 'This sub-site', 'install' => 'Whole website'] as $value => $label) { echo '<p><label><input type="radio" name="scope" value="' . esc_attr($value) . '" ' . checked(Config::scope(), $value, false) . '> ' . esc_html($label) . '</label></p>'; }
        echo '</fieldset>';
        if (apply_filters('uams_search_use_template', true, '')) {
            echo '<p><label><input type="checkbox" name="google" value="1" ' . checked((bool)get_option('uams_search_google', true), true, false) . '> Show the existing All of UAMS Google search</label></p>';
        } else {
            // The theme renders its own results page, including the Google block and its setting.
            echo '<input type="hidden" name="google" value="' . (get_option('uams_search_google', true) ? '1' : '') . '"><p>This theme controls the All of UAMS Google search block in its own search settings.</p>';
        }
        submit_button('Save search settings'); echo '</form></div>';
    }
    public static function save_site(): void {
        if (!current_user_can('manage_options')) { wp_die('You cannot manage this site.'); }
        check_admin_referer('uams_search_site');
        update_option('uams_search_scope', Config::scope(is_scalar($_POST['scope'] ?? '') ? sanitize_text_field(wp_unslash($_POST['scope'])) : 'site'));
        update_option('uams_search_google', !empty($_POST['google']));
        wp_safe_redirect(add_query_arg('notice', 'settings', self::site_home('settings'))); exit;
    }
    public static function retry(): void {
        self::capable(); check_admin_referer('uams_search_retry');
        $site = absint($_GET['site'] ?? 0); $id = absint($_GET['object'] ?? 0);
        if (!in_array($site, Config::public_sites(), true) || !$id) { wp_die('Invalid site or item.'); }
        // Revisions and autosaves are site-local; check them in the target site's context.
        $changed = get_current_blog_id() !== $site;
        if ($changed) { switch_to_blog($site); }
        try { Queue::push($id, 'upsert', $site); } finally { if ($changed) { restore_current_blog(); } }
        wp_safe_redirect(self::home('index') . '#status'); exit;
    }
    public static function page(): void {
        self::capable(); $c = Config::get();
        echo '<div class="wrap uams-admin"><h1>uams search</h1>';
        if (!DB::ready()) {
            echo '<p>Create the index tables to get started.</p><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="uams_search_setup">'; wp_nonce_field('uams_search_setup'); submit_button('Create index tables'); echo '</form></div>'; return;
        }
        $tab = self::current_tab(self::NETWORK_TABS);
        self::tabs([self::class, 'home'], self::NETWORK_TABS, $tab);
        self::saved_notice();
        if ($tab === 'index') {
            echo '<p>Build and compare the index before enabling it. While disabled, signed-in users can preview with <code>?s=heart&amp;engine=uams</code>.</p>';
            self::rebuild_controls(); self::status(); echo '</div>'; return;
        }
        if ($tab === 'featured') { self::featured_section('network'); echo '</div>'; return; }
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="uams_search_save"><input type="hidden" name="section" value="' . esc_attr($tab) . '">'; wp_nonce_field('uams_search_save');
        if ($tab === 'general') { self::general_tab($c); }
        if ($tab === 'content') { self::content_tab($c); }
        if ($tab === 'types') { self::types_tab($c); }
        submit_button('Save settings'); echo '</form></div>';
    }
    // ---------- Settings tabs ----------
    private static function check(string $name, bool $on, string $label): string {
        return '<label><input type="checkbox" name="' . esc_attr($name) . '" value="1" ' . checked($on, true, false) . '> ' . esc_html($label) . '</label>';
    }
    private static function number_input(string $name, string $id, $value, float $max = 100, float $step = 0.1, string $label = ''): string {
        return '<input type="number" min="0" max="' . esc_attr($max) . '" step="' . esc_attr($step) . '" id="' . esc_attr($id) . '" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '"' . ($label !== '' ? ' aria-label="' . esc_attr($label) . '"' : '') . '>';
    }
    private static function general_tab(array $c): void {
        echo '<h2>General</h2><table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row">Visitor search</th><td>' . self::check('settings[enabled]', (bool)$c['enabled'], 'Use uams search for visitors') . '<p class="description">While off, signed-in users can preview with <code>?s=heart&amp;engine=uams</code>.</p></td></tr>';
        echo '<tr><th scope="row">Live suggestions</th><td>' . self::check('settings[live]', (bool)$c['live'], 'Show suggestions as people type') . '</td></tr>';
        echo '<tr><th scope="row">No-result log</th><td>' . self::check('settings[logging]', (bool)$c['logging'], 'Keep search text with no results for up to 90 days') . '<p class="description">Stores only the search text, date and site. Search text can include health terms.</p></td></tr>';
        echo '<tr><th scope="row"><label for="uams-featured-max">Featured results per search</label></th><td>' . self::number_input('settings[featured_max]', 'uams-featured-max', $c['featured_max'], Featured::MAX_ITEMS, 1) . '</td></tr>';
        echo '<tr><th scope="row">PDFs</th><td>' . self::check('settings[pdf]', (bool)$c['pdf'], 'Index PDFs') . '<br>' . self::check('settings[pdf_parent]', (bool)$c['pdf_parent'], 'Link PDFs to their parent page when available');
        echo '<p class="uams-admin-inline"><label for="uams-pdf-mb">Largest PDF, MB</label> ' . self::number_input('settings[pdf_max_mb]', 'uams-pdf-mb', $c['pdf_max_mb'], 100, 1) . ' <label for="uams-pdf-timeout">Time limit, seconds</label> ' . self::number_input('settings[pdf_timeout]', 'uams-pdf-timeout', $c['pdf_timeout'], 120, 1) . '</p></td></tr>';
        echo '<tr><th scope="row">Sub-sites in whole-website search</th><td><fieldset><legend class="screen-reader-text">Sub-sites in whole-website search</legend><ul class="uams-admin-sites">';
        foreach (Config::public_sites() as $site) {
            $label = is_multisite() ? get_blog_details($site)->blogname . ' (#' . $site . ')' : get_bloginfo('name');
            echo '<li><label><input type="checkbox" name="settings[sites][]" value="' . (int)$site . '" ' . checked($c['sites'] === null || in_array($site, $c['sites'], true), true, false) . '> ' . esc_html($label) . '</label></li>';
        }
        echo '</ul></fieldset></td></tr></tbody></table>';
    }
    private static function content_tab(array $c): void {
        echo '<h2>Source weights</h2><p class="uams-admin-note">How much a match in each part of a post counts. Higher numbers rank higher.</p>';
        echo '<table class="widefat striped uams-admin-table uams-admin-weights"><thead><tr>';
        $names = ['title' => 'Title', 'terms' => 'Taxonomy terms', 'fields' => 'ACF fields', 'content' => 'Content', 'pdf' => 'PDF text'];
        foreach (array_keys($c['weights']) as $key) { echo '<th scope="col"><label for="uams-weight-' . esc_attr($key) . '">' . esc_html($names[$key] ?? ucfirst($key)) . '</label></th>'; }
        echo '</tr></thead><tbody><tr>';
        foreach ($c['weights'] as $key => $weight) { echo '<td>' . self::number_input('settings[weights][' . $key . ']', 'uams-weight-' . $key, $weight) . '</td>'; }
        echo '</tr></tbody></table>';

        $types = array_unique(array_merge(array_keys($c['types']), array_keys(get_post_types(['public' => true]))));
        $name_of = static function ($type) { $object = get_post_type_object($type); return $object ? $object->labels->name : ucfirst($type); };
        echo '<h2>Content types</h2><p class="uams-admin-note">Choose what to index and boost whole content types. Rebuild all sites after changing what is indexed.</p>';
        echo '<table class="widefat striped uams-admin-table"><thead><tr><th scope="col">Index</th><th scope="col">Content type</th><th scope="col">Slug</th><th scope="col">Boost</th><th scope="col">Fields</th><th scope="col">Taxonomies</th></tr></thead><tbody>';
        foreach ($types as $type) {
            $name = $name_of($type); $id = 'uams-type-' . sanitize_html_class($type);
            echo '<tr><td><input type="checkbox" id="' . esc_attr($id) . '" name="settings[types][' . esc_attr($type) . '][include]" value="1" ' . checked(isset($c['types'][$type]), true, false) . '></td>';
            echo '<td><label for="' . esc_attr($id) . '"><strong>' . esc_html($name) . '</strong></label></td><td><code>' . esc_html($type) . '</code></td>';
            echo '<td>' . self::number_input('settings[types][' . $type . '][boost]', $id . '-boost', $c['types'][$type] ?? 1, 100, 0.1, $name . ' boost') . '</td>';
            echo '<td>' . count($c['fields'][$type] ?? []) . '</td><td>' . count($c['taxonomies'][$type] ?? []) . '</td></tr>';
        }
        echo '</tbody></table>';

        echo '<h2>Fields and taxonomies</h2><p class="uams-admin-note">Names of the ACF fields and taxonomies to index for each content type. Weights multiply the Fields or Terms source weight. Blank rows are ignored.</p>';
        foreach ($types as $type) {
            $name = $name_of($type);
            echo '<section class="uams-admin-card" aria-labelledby="uams-card-' . esc_attr(sanitize_html_class($type)) . '"><h3 id="uams-card-' . esc_attr(sanitize_html_class($type)) . '">' . esc_html($name) . ' <span class="uams-admin-muted">(' . esc_html($type) . ')' . (isset($c['types'][$type]) ? '' : ', not indexed') . '</span></h3><div class="uams-admin-grid">';
            foreach (['fields' => 'ACF fields', 'taxonomies' => 'Taxonomies'] as $group => $label) {
                $rows = $c[$group][$type] ?? [];
                $rows = array_merge(array_map(static fn($k, $v) => ['name' => $k, 'weight' => $v], array_keys($rows), array_values($rows)), array_fill(0, 2, ['name' => '', 'weight' => 1]));
                echo '<table class="widefat striped"><caption class="screen-reader-text">' . esc_html($name . ' ' . $label) . '</caption><thead><tr><th scope="col">' . esc_html($label) . '</th><th scope="col">Weight</th></tr></thead><tbody>';
                foreach ($rows as $i => $row) {
                    $prefix = 'settings[types][' . $type . '][' . $group . '][' . $i . ']';
                    echo '<tr><td><input type="text" aria-label="' . esc_attr($name . ' ' . $label . ' name ' . ($i + 1)) . '" name="' . esc_attr($prefix . '[name]') . '" value="' . esc_attr($row['name']) . '"></td><td>' . self::number_input($prefix . '[weight]', '', $row['weight'], 100, 0.1, $name . ' ' . $label . ' weight ' . ($i + 1)) . '</td></tr>';
                }
                echo '</tbody></table>';
            }
            echo '</div></section>';
        }

        $synonyms = []; foreach ($c['synonyms'] as $key => $values) { $synonyms[] = $key . ' = ' . implode(', ', $values); }
        echo '<h2>Words</h2><div class="uams-admin-grid"><p><label for="uams-stopwords"><strong>Extra stop words</strong></label><br><span id="uams-stopwords-help" class="description">Separated by spaces. Rebuild after changing.</span><br><textarea class="large-text" id="uams-stopwords" name="settings[stopwords]" rows="5" aria-describedby="uams-stopwords-help">' . esc_textarea(implode(' ', $c['stopwords'])) . '</textarea></p>';
        echo '<p><label for="uams-synonyms"><strong>Synonyms</strong></label><br><span id="uams-synonyms-help" class="description">One rule per line, such as <code>heart = cardiac, cardiology</code>.</span><br><textarea class="large-text" id="uams-synonyms" name="settings[synonyms]" rows="5" aria-describedby="uams-synonyms-help">' . esc_textarea(implode("\n", $synonyms)) . '</textarea></p></div>';
    }
    private static function types_tab(array $c): void {
        echo '<h2>Result types</h2><p class="uams-admin-note">The type links above search results, in this order. One link can cover several post types; separate them with commas. A link only shows when it has results. Clear a row\'s label to remove it.</p>';
        $rows = [];
        foreach ((array)$c['groups'] as $slug => $group) { $rows[] = ['slug' => $slug, 'label' => $group['label'] ?? $slug, 'types' => implode(', ', (array)($group['types'] ?? []))]; }
        $rows = array_merge($rows, array_fill(0, 2, ['slug' => '', 'label' => '', 'types' => '']));
        echo '<table class="widefat striped uams-admin-table"><thead><tr><th scope="col">Order</th><th scope="col">Label</th><th scope="col">Post types</th><th scope="col">Slug in the URL</th></tr></thead><tbody>';
        foreach ($rows as $i => $row) {
            $p = 'settings[groups][' . $i . ']'; $n = $i + 1;
            echo '<tr><td>' . self::number_input($p . '[order]', '', $n, 99, 1, 'Order, row ' . $n) . '</td>';
            echo '<td><input type="text" aria-label="Label, row ' . $n . '" name="' . esc_attr($p . '[label]') . '" value="' . esc_attr($row['label']) . '"></td>';
            echo '<td><input type="text" aria-label="Post types, row ' . $n . '" name="' . esc_attr($p . '[types]') . '" value="' . esc_attr($row['types']) . '"></td>';
            echo '<td><input type="text" aria-label="Slug, row ' . $n . '" name="' . esc_attr($p . '[slug]') . '" value="' . esc_attr($row['slug']) . '"></td></tr>';
        }
        echo '</tbody></table>';
    }
    // ---------- Featured results ----------
    private static function featured_scope(): string {
        $scope = isset($_REQUEST['scope']) && $_REQUEST['scope'] === 'network' && is_multisite() ? 'network' : 'site';
        if (!current_user_can($scope === 'network' ? 'manage_network_options' : 'manage_options')) { wp_die('You cannot manage featured search results.'); }
        return $scope;
    }
    private static function featured_url(string $scope, array $args = []): string {
        return add_query_arg($args, $scope === 'network' ? self::home('featured') : self::site_home('featured'));
    }
    /** Featured results tab, on the network page or a site's Search page. */
    public static function featured_section(string $scope): void {
        if (!current_user_can($scope === 'network' ? 'manage_network_options' : 'manage_options')) { wp_die('You cannot manage featured search results.'); }
        $rules = Featured::rules($scope);
        $editing = null; $edit_id = Integration::param('edit');
        foreach ($rules as $rule) { if ($edit_id !== '' && ($rule['id'] ?? '') === $edit_id) { $editing = $rule; } }
        echo '<p>' . ($scope === 'network'
            ? 'These rules apply to searches on every sub-site of this website. Each sub-site\'s own rules show first.'
            : 'These rules apply to searches on this site. Network admins can also add rules for the whole website.') . '</p>';
        echo '<p>Featured results appear in a box above the regular results on the first page, when a search contains one of the rule\'s keywords or phrases. "cancer" also matches "cancers" and "breast cancer". Up to ' . (int)Config::get()['featured_max'] . ' items show per search.</p>';
        $notice = Integration::param('notice');
        $messages = ['saved' => 'Featured result saved.', 'deleted' => 'Featured result deleted.', 'toggled' => 'Featured result updated.', 'invalid' => 'Not saved. Add at least one keyword and one item. Pages must be on this website; external links need a title.'];
        if (isset($messages[$notice])) { echo '<div class="notice ' . ($notice === 'invalid' ? 'notice-error' : 'notice-success') . '" role="' . ($notice === 'invalid' ? 'alert' : 'status') . '"><p>' . esc_html($messages[$notice]) . '</p></div>'; }
        echo '<h2>' . ($editing ? 'Edit featured result' : 'Current featured results') . '</h2>';
        if (!$editing) {
            if (!$rules) { echo '<p>No featured results yet.</p>'; }
            else {
                echo '<table class="widefat striped"><thead><tr><th scope="col">Keywords</th><th scope="col">Shows</th><th scope="col">Status</th><th scope="col">Actions</th></tr></thead><tbody>';
                foreach ($rules as $rule) {
                    $titles = array_map(static fn($i) => ($i['title'] ?? '') !== '' ? $i['title'] : ($i['url'] ?? ''), (array)$rule['items']);
                    echo '<tr><td>' . esc_html(implode(', ', (array)$rule['keywords'])) . '</td><td>' . esc_html(implode('; ', $titles)) . '</td><td>' . (empty($rule['active']) ? 'Off' : 'On') . '</td><td>';
                    echo '<a href="' . esc_url(self::featured_url($scope, ['edit' => $rule['id']])) . '">Edit<span class="screen-reader-text"> ' . esc_html(implode(', ', (array)$rule['keywords'])) . '</span></a> ';
                    foreach (['toggle' => empty($rule['active']) ? 'Turn on' : 'Turn off', 'delete' => 'Delete'] as $op => $label) {
                        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">';
                        wp_nonce_field('uams_search_featured_action');
                        echo '<input type="hidden" name="action" value="uams_search_featured_action"><input type="hidden" name="scope" value="' . esc_attr($scope) . '"><input type="hidden" name="op" value="' . esc_attr($op) . '"><input type="hidden" name="id" value="' . esc_attr($rule['id']) . '">';
                        echo '<button type="submit" class="button-link' . ($op === 'delete' ? ' button-link-delete' : '') . '">' . esc_html($label) . '<span class="screen-reader-text"> ' . esc_html(implode(', ', (array)$rule['keywords'])) . '</span></button></form> ';
                    }
                    echo '</td></tr>';
                }
                echo '</tbody></table>';
            }
            echo '<h2>Add a featured result</h2>';
        }
        self::featured_form($scope, $editing);
    }
    private static function featured_form(string $scope, ?array $rule): void {
        $items = array_values((array)($rule['items'] ?? []));
        $count = max(3, count($items) + 1); $count = min(Featured::MAX_ITEMS, $count);
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('uams_search_featured');
        echo '<input type="hidden" name="action" value="uams_search_featured"><input type="hidden" name="scope" value="' . esc_attr($scope) . '">';
        if ($rule) { echo '<input type="hidden" name="id" value="' . esc_attr($rule['id']) . '">'; }
        echo '<p><label for="uams-featured-keywords"><strong>Keywords and phrases</strong></label><br><span id="uams-featured-keywords-help" class="description">One per line, such as <em>cancer</em> or <em>pay my bill</em>.</span><br>';
        echo '<textarea id="uams-featured-keywords" name="rule[keywords]" rows="4" cols="60" aria-describedby="uams-featured-keywords-help" required>' . esc_textarea(implode("\n", (array)($rule['keywords'] ?? []))) . '</textarea></p>';
        echo '<p><label><input type="checkbox" name="rule[active]" value="1" ' . checked($rule ? !empty($rule['active']) : true, true, false) . '> Show this featured result</label></p>';
        for ($i = 0; $i < $count; $i++) {
            $item = $items[$i] ?? ['kind' => 'post'];
            $p = 'rule[items][' . $i . ']'; $id = 'uams-featured-' . $i;
            if ($i === 0) { echo '<h3>Items to feature</h3><div class="uams-admin-items">'; }
            echo '<fieldset><legend>Item ' . ($i + 1) . ($i > 0 ? ' <span class="uams-admin-muted">(optional)</span>' : '') . '</legend>';
            echo '<p><label for="' . $id . '-kind">Type</label><select id="' . $id . '-kind" name="' . $p . '[kind]">';
            foreach (['post' => 'Page or post on this website', 'link' => 'External link'] as $value => $label) { echo '<option value="' . $value . '" ' . selected($item['kind'] ?? 'post', $value, false) . '>' . esc_html($label) . '</option>'; }
            echo '</select></p>';
            $fields = [
                'url' => ['URL', 'For a page on this website, paste its address. For an external link, the full address.', 'url'],
                'title' => ['Title', 'Required for external links. For pages, leave blank to use the page title.', 'text'],
                'description' => ['Description', 'Leave blank to use the page excerpt.', 'textarea'],
                'image' => ['Image URL', 'Optional. Leave blank to use the page\'s featured image.', 'url'],
                'image_alt' => ['Image alternative text', 'Describe the image. Leave blank if it is decorative.', 'text'],
            ];
            foreach ($fields as $key => [$label, $help, $type]) {
                $value = (string)($item[$key] ?? '');
                echo '<p><label for="' . $id . '-' . $key . '">' . esc_html($label) . '</label><span id="' . $id . '-' . $key . '-help" class="description">' . esc_html($help) . '</span>';
                if ($type === 'textarea') { echo '<textarea id="' . $id . '-' . $key . '" name="' . $p . '[' . $key . ']" rows="2" cols="60" aria-describedby="' . $id . '-' . $key . '-help">' . esc_textarea($value) . '</textarea>'; }
                else { echo '<input type="' . $type . '" class="regular-text" id="' . $id . '-' . $key . '" name="' . $p . '[' . $key . ']" value="' . esc_attr($value) . '" aria-describedby="' . $id . '-' . $key . '-help">'; }
                echo '</p>';
            }
            echo '</fieldset>';
        }
        echo '</div>';
        submit_button($rule ? 'Save featured result' : 'Add featured result');
        if ($rule) { echo '<p><a href="' . esc_url(self::featured_url($scope)) . '">Cancel</a></p>'; }
        echo '</form>';
    }
    public static function featured_save(): void {
        check_admin_referer('uams_search_featured');
        $scope = self::featured_scope();
        $input = isset($_POST['rule']) && is_array($_POST['rule']) ? wp_unslash($_POST['rule']) : [];
        $id = isset($_POST['id']) && is_scalar($_POST['id']) ? sanitize_text_field(wp_unslash((string)$_POST['id'])) : '';
        $rules = Featured::rules($scope);
        $rule = Featured::sanitize_rule($input, $id ?: null);
        if (!$rule) { wp_safe_redirect(self::featured_url($scope, array_filter(['notice' => 'invalid', 'edit' => $id]))); exit; }
        $replaced = false;
        foreach ($rules as $i => $existing) { if ($id !== '' && ($existing['id'] ?? '') === $id) { $rules[$i] = $rule; $replaced = true; } }
        if (!$replaced) { $rules[] = $rule; }
        Featured::save_rules($scope, $rules);
        wp_safe_redirect(self::featured_url($scope, ['notice' => 'saved'])); exit;
    }
    public static function featured_action(): void {
        check_admin_referer('uams_search_featured_action');
        $scope = self::featured_scope();
        $id = isset($_POST['id']) && is_scalar($_POST['id']) ? sanitize_text_field(wp_unslash((string)$_POST['id'])) : '';
        $op = isset($_POST['op']) && is_scalar($_POST['op']) ? sanitize_key(wp_unslash((string)$_POST['op'])) : '';
        $rules = Featured::rules($scope);
        foreach ($rules as $i => $rule) {
            if (($rule['id'] ?? '') !== $id) { continue; }
            if ($op === 'delete') { unset($rules[$i]); }
            elseif ($op === 'toggle') { $rules[$i]['active'] = empty($rule['active']); }
        }
        Featured::save_rules($scope, $rules);
        wp_safe_redirect(self::featured_url($scope, ['notice' => $op === 'delete' ? 'deleted' : 'toggled'])); exit;
    }
    public static function rebuild_controls(): void {
        $include = Config::get()['preview_noindex'] || (!Config::site_ids(false) && Config::site_ids(true));
        echo '<section id="uams-search-rebuild" aria-labelledby="uams-rebuild-heading"><h2 id="uams-rebuild-heading">Rebuild index</h2>';
        echo '<p>Build the full index without command-line access. Keep this page open while it runs. If you leave, come back and choose Resume rebuild.</p>';
        echo '<p>Existing results stay available. Each site switches to its new index when its content is ready. Saved edits wait in the queue until the rebuild finishes or is cancelled.</p>';
        echo '<p><label><input id="uams-rebuild-noindex" type="checkbox" ' . checked((bool)$include, true, false) . '> Include no-index sites for signed-in previews</label><br><span class="description">This does not change search-engine visibility. No-index sites stay excluded from signed-out search results.</span></p>';
        echo '<p><button type="button" id="uams-rebuild-start" class="button button-primary">Rebuild index</button> <button type="button" id="uams-rebuild-resume" class="button button-primary" hidden>Resume rebuild</button> <button type="button" id="uams-rebuild-pause" class="button" hidden>Pause rebuild</button> <button type="button" id="uams-rebuild-cancel" class="button" hidden>Cancel rebuild</button></p>';
        echo '<progress id="uams-rebuild-progress" aria-label="Index rebuild progress" value="0" max="100"></progress>';
        echo '<p id="uams-rebuild-status" role="status" aria-live="polite" aria-atomic="true">Ready to rebuild.</p><p id="uams-rebuild-counts"></p><p id="uams-rebuild-error" role="alert" hidden></p>';
        echo '<noscript><p>JavaScript is required for the browser rebuild. Enable it, then reload this page.</p></noscript></section>';
    }
    private static function numeric(string $name, string $label, $value, int $max = 100): void {
        echo '<p><label>' . esc_html($label) . ' <input type="number" min="0" max="' . $max . '" step="0.1" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '"></label></p>';
    }
    private static function status(): void {
        global $wpdb;
        echo '<section id="status"><h2>Index status</h2><p>Queue length: ' . (int)$wpdb->get_var('SELECT COUNT(*) FROM `' . DB::table('queue') . '`') . '. Last queue run (UTC): ' . esc_html(get_site_option('uams_search_last_queue', 'Never')) . '.</p>';
        $rows = $wpdb->get_results('SELECT site_id,post_type,status,COUNT(*) AS total FROM `' . DB::table() . '` GROUP BY site_id,post_type,status ORDER BY site_id,post_type', ARRAY_A);
        self::table(['Site','Post type','Status','Count'], array_map('array_values', $rows));
        echo '<h3>Failed items and PDFs without text</h3><p>Showing the latest 100 items. PDFs without text still match their titles and filenames.</p>';
        $rows = $wpdb->get_results('SELECT site_id,object_id,status,error FROM `' . DB::table() . "` WHERE status IN ('error','scanned') ORDER BY indexed_at DESC LIMIT 100", ARRAY_A);
        echo '<table class="widefat striped"><thead><tr><th scope="col">Site</th><th scope="col">Item</th><th scope="col">Status</th><th scope="col">Message</th><th scope="col">Action</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $url = wp_nonce_url(add_query_arg(['action' => 'uams_search_retry', 'site' => $row['site_id'], 'object' => $row['object_id']], admin_url('admin-post.php')), 'uams_search_retry');
            echo '<tr>'; foreach ($row as $value) { echo '<td>' . esc_html($value) . '</td>'; } echo '<td><a href="' . esc_url($url) . '">Retry item ' . (int)$row['object_id'] . ' on site ' . (int)$row['site_id'] . '</a></td></tr>';
        }
        echo '</tbody></table><h3>Searches with no results, last 30 days</h3><p>Search text can include health terms. No IP address or user ID is stored. Entries are deleted after 90 days.</p>';
        $rows = $wpdb->get_results('SELECT site_id,search_text,SUM(count) AS total FROM `' . DB::table('misses') . '` WHERE day>=UTC_DATE()-INTERVAL 30 DAY GROUP BY site_id,search_hash,search_text ORDER BY total DESC LIMIT 100', ARRAY_A);
        self::table(['Site','Search','Count'], array_map('array_values', $rows)); echo '</section>';
    }
    private static function table(array $heads, array $rows): void {
        echo '<table class="widefat striped"><thead><tr>'; foreach ($heads as $head) { echo '<th scope="col">' . esc_html($head) . '</th>'; } echo '</tr></thead><tbody>';
        foreach ($rows as $row) { echo '<tr>'; foreach ($row as $value) { echo '<td>' . esc_html($value) . '</td>'; } echo '</tr>'; } echo '</tbody></table>';
    }
}
