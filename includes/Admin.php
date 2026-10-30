<?php
namespace UAMS_Search;

final class Admin {
    public static function hooks(): void {
        add_action('network_admin_menu', static fn() => add_submenu_page('settings.php', 'uams search', 'uams search', 'manage_network_options', 'uams-search', [self::class, 'page']));
        add_action('admin_menu', static function () {
            add_options_page('uams search', 'uams search', 'manage_options', 'uams-search-site', [self::class, 'site_page']);
            if (!is_multisite()) { add_options_page('uams search index', 'uams search index', 'manage_options', 'uams-search', [self::class, 'page']); }
            // Use the existing UAMS Settings menu when it is available, without assuming a plugin slug.
            global $menu;
            foreach ($menu as $item) {
                if (strip_tags($item[0]) === 'UAMS Settings') { add_submenu_page($item[2], 'Search', 'Search', 'manage_options', 'uams-search-site', [self::class, 'site_page']); break; }
            }
        }, 99);
        add_action('admin_post_uams_search_save', [self::class, 'save']);
        add_action('admin_post_uams_search_site', [self::class, 'save_site']);
        add_action('admin_post_uams_search_retry', [self::class, 'retry']);
        add_action('admin_post_uams_search_setup', [self::class, 'setup']);
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
    private static function home(): string { return is_multisite() ? network_admin_url('settings.php?page=uams-search') : admin_url('options-general.php?page=uams-search'); }
    public static function notice(): void {
        if (!current_user_can(is_multisite() ? 'manage_network_options' : 'manage_options')) { return; }
        if (!DB::ready()) { echo '<div class="notice notice-warning"><p>uams search needs its index tables. <a href="' . esc_url(self::home()) . '">Open search settings</a>.</p></div>'; }
        elseif (Config::needs_rebuild()) { echo '<div class="notice notice-warning"><p>Some uams search sites need a rebuild. Rebuild every site before comparing or switching search engines.</p></div>'; }
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
        foreach (['enabled','live','logging','pdf','pdf_parent'] as $key) { $c[$key] = !empty($input[$key]); }
        $c['sites'] = array_values(array_intersect(Config::public_sites(), array_map('intval', (array)($input['sites'] ?? []))));
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
        $c['pdf_max_mb'] = max(1, min(100, self::number($input['pdf_max_mb'] ?? 20)));
        $c['pdf_timeout'] = max(1, min(120, (int)($input['pdf_timeout'] ?? 30)));
        if ($c['enabled'] && !DB::ready()) { wp_die('Create and build the index before enabling search.'); }
        update_site_option('uams_search_settings', $c);
        wp_safe_redirect(self::home()); exit;
    }
    private static function number($value): float { return is_scalar($value) && is_numeric($value) && is_finite((float)$value) ? max(0, min(100, (float)$value)) : 0; }
    public static function site_page(): void {
        if (!current_user_can('manage_options')) { wp_die('You cannot manage this site.'); }
        echo '<div class="wrap"><h1>Search settings</h1><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="uams_search_site">';
        wp_nonce_field('uams_search_site');
        echo '<fieldset><legend><h2>Default search scope</h2></legend>';
        foreach (['site' => 'This sub-site', 'install' => 'Whole website'] as $value => $label) { echo '<p><label><input type="radio" name="scope" value="' . esc_attr($value) . '" ' . checked(Config::scope(), $value, false) . '> ' . esc_html($label) . '</label></p>'; }
        echo '</fieldset><p><label><input type="checkbox" name="google" value="1" ' . checked((bool)get_option('uams_search_google', true), true, false) . '> Show the existing All of UAMS Google search</label></p>';
        submit_button('Save search settings'); echo '</form></div>';
    }
    public static function save_site(): void {
        if (!current_user_can('manage_options')) { wp_die('You cannot manage this site.'); }
        check_admin_referer('uams_search_site');
        update_option('uams_search_scope', Config::scope(is_scalar($_POST['scope'] ?? '') ? sanitize_text_field(wp_unslash($_POST['scope'])) : 'site'));
        update_option('uams_search_google', !empty($_POST['google']));
        wp_safe_redirect(admin_url('options-general.php?page=uams-search-site')); exit;
    }
    public static function retry(): void {
        self::capable(); check_admin_referer('uams_search_retry');
        $site = absint($_GET['site'] ?? 0); $id = absint($_GET['object'] ?? 0);
        if (!in_array($site, Config::public_sites(), true) || !$id) { wp_die('Invalid site or item.'); }
        // Revisions and autosaves are site-local; check them in the target site's context.
        $changed = get_current_blog_id() !== $site;
        if ($changed) { switch_to_blog($site); }
        try { Queue::push($id, 'upsert', $site); } finally { if ($changed) { restore_current_blog(); } }
        wp_safe_redirect(self::home() . '#status'); exit;
    }
    public static function page(): void {
        self::capable(); $c = Config::get();
        echo '<div class="wrap"><h1>uams search</h1><p>Build and compare the index before enabling it. While disabled, signed-in users can preview with <code>?s=heart&amp;engine=uams</code>.</p>';
        if (!DB::ready()) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="uams_search_setup">'; wp_nonce_field('uams_search_setup'); submit_button('Create index tables'); echo '</form></div>'; return;
        }
        self::status();
        echo '<h2>Network settings</h2><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="uams_search_save">'; wp_nonce_field('uams_search_save');
        foreach (['enabled' => 'Use uams search for visitors', 'live' => 'Show live search suggestions', 'logging' => 'Keep search text with no results for up to 90 days', 'pdf' => 'Index PDFs', 'pdf_parent' => 'Link PDFs to their parent page when available'] as $key => $label) {
            echo '<p><label><input type="checkbox" name="settings[' . esc_attr($key) . ']" value="1" ' . checked((bool)$c[$key], true, false) . '> ' . esc_html($label) . '</label></p>';
        }
        echo '<fieldset><legend><h3>Sub-sites in whole-website search</h3></legend>';
        foreach (Config::public_sites() as $site) {
            $label = is_multisite() ? get_blog_details($site)->blogname . ' (#' . $site . ')' : get_bloginfo('name');
            echo '<p><label><input type="checkbox" name="settings[sites][]" value="' . (int)$site . '" ' . checked($c['sites'] === null || in_array($site, $c['sites'], true), true, false) . '> ' . esc_html($label) . '</label></p>';
        }
        echo '</fieldset><fieldset><legend><h3>Source weights</h3></legend>';
        foreach ($c['weights'] as $key => $weight) { self::numeric('settings[weights][' . $key . ']', ucfirst($key), $weight); }
        echo '</fieldset><h3>Content to index</h3><p>Field and taxonomy weights multiply the source weight. Blank rows are ignored. Rebuild all sites after changing indexed content or stop words.</p>';
        $types = array_unique(array_merge(array_keys($c['types']), array_keys(get_post_types(['public' => true]))));
        foreach ($types as $type) {
            $object = get_post_type_object($type); $name = $object ? $object->labels->name : ucfirst($type);
            echo '<fieldset style="padding:1rem;border:1px solid #aaa;margin-block:1rem"><legend><strong>' . esc_html($name . ' (' . $type . ')') . '</strong></legend>';
            echo '<p><label><input type="checkbox" name="settings[types][' . esc_attr($type) . '][include]" value="1" ' . checked(isset($c['types'][$type]), true, false) . '> Include this content type</label></p>';
            self::numeric('settings[types][' . $type . '][boost]', 'Type boost', $c['types'][$type] ?? 1);
            foreach (['fields' => 'ACF fields', 'taxonomies' => 'Taxonomies'] as $group => $label) {
                $rows = $c[$group][$type] ?? [];
                echo '<h4>' . esc_html($label) . '</h4><table><thead><tr><th scope="col">Name</th><th scope="col">Weight</th></tr></thead><tbody>';
                $rows = array_merge(array_map(static fn($k, $v) => ['name' => $k, 'weight' => $v], array_keys($rows), array_values($rows)), array_fill(0, 3, ['name' => '', 'weight' => 1]));
                foreach ($rows as $i => $row) {
                    $prefix = 'settings[types][' . $type . '][' . $group . '][' . $i . ']';
                    echo '<tr><td><input aria-label="' . esc_attr($name . ' ' . $label . ' name ' . ($i + 1)) . '" name="' . esc_attr($prefix . '[name]') . '" value="' . esc_attr($row['name']) . '"></td><td><input type="number" min="0" max="100" step="0.1" aria-label="' . esc_attr($name . ' ' . $label . ' weight ' . ($i + 1)) . '" name="' . esc_attr($prefix . '[weight]') . '" value="' . esc_attr($row['weight']) . '"></td></tr>';
                }
                echo '</tbody></table>';
            }
            echo '</fieldset>';
        }
        echo '<p><label for="uams-stopwords">Extra stop words, separated by spaces</label><br><textarea id="uams-stopwords" name="settings[stopwords]" rows="3" cols="70">' . esc_textarea(implode(' ', $c['stopwords'])) . '</textarea></p>';
        $synonyms = []; foreach ($c['synonyms'] as $key => $values) { $synonyms[] = $key . ' = ' . implode(', ', $values); }
        echo '<p><label for="uams-synonyms">Synonyms, one rule per line, such as heart = cardiac, cardiology</label><br><textarea id="uams-synonyms" name="settings[synonyms]" rows="5" cols="70">' . esc_textarea(implode("\n", $synonyms)) . '</textarea></p>';
        self::numeric('settings[pdf_max_mb]', 'Largest PDF, MB', $c['pdf_max_mb']); self::numeric('settings[pdf_timeout]', 'PDF time limit, seconds', $c['pdf_timeout'], 120);
        submit_button('Save network settings'); echo '</form></div>';
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
