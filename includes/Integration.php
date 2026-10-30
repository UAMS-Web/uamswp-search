<?php
namespace UAMS_Search;

final class Integration {
    public static array $excerpts = [];
    public static function param(string $name, string $default = ''): string {
        return isset($_GET[$name]) && is_scalar($_GET[$name]) ? sanitize_text_field(wp_unslash((string)$_GET[$name])) : $default;
    }
    public static function active(): bool {
        if (!DB::ready()) { return false; }
        if (is_user_logged_in() && self::param('engine') === 'wordpress') { return false; }
        return (bool)Config::get()['enabled'] || (is_user_logged_in() && self::param('engine') === 'uams');
    }
    public static function args(): array {
        return ['s' => get_search_query(false), 'site' => get_current_blog_id(), 'scope' => Config::scope(self::param('scope')), 'type' => self::param('type'), 'page' => max(1, (int)get_query_var('paged', 1)), 'per_page' => max(1, (int)get_option('posts_per_page', 10))];
    }
    public static function hooks(): void {
        add_filter('posts_pre_query', [self::class, 'posts'], 1, 2);
        add_filter('relevanssi_search_ok', [self::class, 'allow_other_engine'], PHP_INT_MAX, 2);
        add_filter('asp_query_is_search', [self::class, 'allow_other_engine'], PHP_INT_MAX, 2);
        add_filter('template_include', [self::class, 'template'], 99);
        add_filter('get_the_excerpt', [self::class, 'excerpt'], 99, 2);
        add_filter('get_search_form', [self::class, 'form'], 99);
        add_action('wp_enqueue_scripts', [self::class, 'assets']);
        add_action('rest_api_init', [self::class, 'rest']);
        add_shortcode('uams_search_form', static fn() => get_search_form(false));
    }
    public static function allow_other_engine($allowed, $query) {
        // Only yield a query we already answered. Other engines keep normal searches,
        // secondary queries, and the error/missing-index fallback they handled before.
        return $query instanceof \WP_Query && is_array($query->get('uams_search_results')) ? false : $allowed;
    }
    public static function posts($posts, \WP_Query $query) {
        if ($posts !== null || !$query->is_main_query() || !$query->is_search() || !in_array($query->get('fields'), ['', 'all'], true) || is_admin() || !self::active()) { return $posts; }
        try {
            $args = ['s' => (string)$query->get('s'), 'site' => get_current_blog_id(), 'scope' => Config::scope(self::param('scope')), 'type' => self::param('type'), 'page' => max(1, (int)$query->get('paged')), 'per_page' => max(1, (int)($query->get('posts_per_page') ?: 10))];
            $results = Query::run($args); $out = [];
            foreach ($results['results'] as $result) {
                $changed = get_current_blog_id() !== $result->site;
                if ($changed) { switch_to_blog($result->site); }
                try { $post = get_post($result->id); if ($post) { $post = clone $post; $post->id = $result->id; $post->site = $result->site; $post->blog_id = $result->site; $post->relevance = $result->relevance; $post->excerpt = $result->excerpt; $out[] = $post; self::$excerpts[$result->site . ':' . $result->id] = $result->excerpt; } }
                finally { if ($changed) { restore_current_blog(); } }
            }
            $query->found_posts = $results['total']; $query->max_num_pages = $results['pages'];
            // WordPress' ordinary post cache is keyed by local ID only. Cross-site IDs must never enter it.
            $query->set('cache_results', false);
            $query->set('update_post_meta_cache', false);
            $query->set('update_post_term_cache', false);
            $query->set('uams_search_results', $results);
            return $out;
        } catch (\Throwable $e) { error_log('UAMS Search: search failed; using WordPress. ' . $e->getMessage()); return $posts; }
    }
    public static function template(string $template): string {
        global $wp_query;
        if (!is_search() || !self::active() || !$wp_query->get('uams_search_results')) { return $template; }
        if (get_template() === 'genesis' || get_stylesheet() === 'uams-2020') { return dirname(__DIR__) . '/templates/search-genesis.php'; }
        return apply_filters('uams_search_template', dirname(__DIR__) . '/templates/search.php', $template);
    }
    public static function excerpt($excerpt, $post) {
        if (!$post || !is_search() || !self::active()) { return $excerpt; }
        return self::$excerpts[($post->site ?? get_current_blog_id()) . ':' . $post->ID] ?? $excerpt;
    }
    public static function form(string $form): string {
        if (!self::active()) { return $form; }
        $id = wp_unique_id('uams-search-');
        $scope = Config::scope(self::param('scope'));
        $html = '<form role="search" method="get" action="' . esc_url(home_url('/')) . '" class="uams-search-form"><label for="' . esc_attr($id) . '">Search this website</label><div class="uams-search-input"><input type="search" name="s" id="' . esc_attr($id) . '" value="' . esc_attr(get_search_query(false)) . '" maxlength="200" autocomplete="off" data-uams-live="' . (Config::get()['live'] ? 'true' : 'false') . '"><button type="submit">Search</button></div><input type="hidden" name="scope" value="' . esc_attr($scope) . '">';
        if (is_user_logged_in() && self::param('engine') === 'uams') { $html .= '<input type="hidden" name="engine" value="uams">'; }
        if (isset(Config::get()['types'][self::param('type')])) { $html .= '<input type="hidden" name="type" value="' . esc_attr(self::param('type')) . '">'; }
        return $html . '</form>';
    }
    public static function assets(): void {
        if (!self::active()) { return; }
        wp_enqueue_style('uams-search', plugins_url('assets/search.css', UAMS_SEARCH_FILE), [], UAMS_SEARCH_VERSION);
        if (Config::get()['live']) {
            wp_enqueue_script('uams-search', plugins_url('assets/live-search.js', UAMS_SEARCH_FILE), [], UAMS_SEARCH_VERSION, true);
            wp_localize_script('uams-search', 'UAMSSearch', ['endpoint' => rest_url('uams-search/v1/suggest'), 'nonce' => is_user_logged_in() ? wp_create_nonce('wp_rest') : '', 'preview' => is_user_logged_in() && self::param('engine') === 'uams']);
        }
    }
    public static function rest(): void {
        register_rest_route('uams-search/v1', '/suggest', [
            'methods' => 'GET',
            'permission_callback' => static function ($request) {
                return DB::ready() && Config::get()['live'] && (Config::get()['enabled'] || (is_user_logged_in() && $request->get_param('engine') === 'uams'));
            },
            'args' => [
                's' => ['required' => true, 'type' => 'string', 'maxLength' => 200, 'minLength' => 2, 'sanitize_callback' => 'sanitize_text_field'],
                'scope' => ['type' => 'string', 'enum' => ['site','install']],
                'type' => ['type' => 'string', 'sanitize_callback' => 'sanitize_key'],
                'engine' => ['type' => 'string', 'enum' => ['uams']],
            ],
            'callback' => static function ($request) {
                try {
                    $out = Query::run(['s' => $request['s'], 'site' => get_current_blog_id(), 'scope' => Config::scope($request->get_param('scope')), 'type' => $request->get_param('type') ?: '', 'per_page' => 6, 'suggest' => false]);
                    $items = array_map(static fn($r) => ['id' => $r->id, 'site' => $r->site, 'title' => $r->title . ($r->post_type === 'attachment' ? ' (PDF, ' . size_format($r->file_size) . ')' : ''), 'url' => esc_url_raw($r->url)], $out['results']);
                    $response = new \WP_REST_Response(['items' => $items, 'total' => $out['total']], 200);
                    $response->header('Cache-Control', 'no-store'); return $response;
                } catch (\Throwable $e) { error_log('UAMS Search: REST ' . $e->getMessage()); return new \WP_Error('uams_search_unavailable', 'Search suggestions are unavailable.', ['status' => 503]); }
            },
        ]);
    }
    public static function render(): void {
        global $wp_query;
        $out = $wp_query->get('uams_search_results');
        if (!$out) { return; }
        echo '<section class="uams-search-results" aria-label="Search results">';
        echo '<h1>Search results for: ' . esc_html(get_search_query(false)) . '</h1>';
        echo self::form('');
        self::filters();
        echo '<h2>' . esc_html(number_format_i18n($out['total']) . ' results') . '</h2>';
        if ($out['fallback']) { echo '<p>Not every word matched. These results match some of your words.</p>'; }
        if ($out['suggestion']) { echo '<p>Did you mean <a href="' . esc_url(self::url(['s' => $out['suggestion'], 'paged' => false])) . '">' . esc_html($out['suggestion']) . '</a>?</p>'; }
        if (!$out['total']) { echo '<p>No results found. Try fewer words or check the spelling.</p>'; if ((int)get_query_var('paged', 1) <= 1) { Misses::record(get_search_query(false)); } }
        $provider_group = false;
        foreach ($out['results'] as $result) {
            if ($result->post_type === 'provider') {
                $card = ProviderCard::data($result);
                if (!$card) { continue; }
                if (!$provider_group) { echo '<div class="uams-search-provider-cards card-list card-list-doctors">'; $provider_group = true; }
                ProviderCard::render($card); continue;
            }
            if ($provider_group) { echo '</div>'; $provider_group = false; }
            echo '<article class="uams-search-result"><h3><a href="' . esc_url($result->url) . '">' . esc_html($result->title ?: 'Untitled');
            if ($result->post_type === 'attachment') { echo ' <span>(PDF, ' . esc_html(size_format($result->file_size)) . ')</span>'; }
            echo '</a></h3><p>' . wp_kses($result->excerpt, ['mark' => []]) . '</p></article>';
        }
        if ($provider_group) { echo '</div>'; }
        // paginate_links expects a literal placeholder in base.
        if ($out['pages'] > 1) {
            $links = paginate_links(['base' => str_replace('999999999', '%#%', self::url(['paged' => 999999999])), 'current' => max(1, (int)get_query_var('paged', 1)), 'total' => $out['pages'], 'type' => 'list']);
            echo '<nav aria-label="Search result pages">' . wp_kses_post($links) . '</nav>';
        }
        self::google(); echo '</section>';
    }
    public static function url(array $changes = []): string {
        $args = ['s' => get_search_query(false), 'scope' => Config::scope(self::param('scope'))];
        if (self::param('type')) { $args['type'] = self::param('type'); }
        if (is_user_logged_in() && self::param('engine') === 'uams') { $args['engine'] = 'uams'; }
        return add_query_arg(array_merge($args, $changes), home_url('/'));
    }
    private static function filters(): void {
        echo '<nav aria-label="Search scope"><ul class="uams-search-filters">';
        foreach (['site' => 'This site', 'install' => 'Whole website'] as $scope => $label) {
            echo '<li><a href="' . esc_url(self::url(['scope' => $scope, 'paged' => false])) . '"' . (Config::scope(self::param('scope')) === $scope ? ' aria-current="true"' : '') . '>' . esc_html($label) . '</a></li>';
        }
        echo '</ul></nav><nav aria-label="Result type"><ul class="uams-search-filters"><li><a href="' . esc_url(self::url(['type' => false, 'paged' => false])) . '"' . (self::param('type') === '' ? ' aria-current="true"' : '') . '>All types</a></li>';
        foreach (Config::get()['types'] as $type => $boost) {
            $object = get_post_type_object($type);
            if (!$object || ($type === 'attachment' && !Config::get()['pdf'])) { continue; }
            echo '<li><a href="' . esc_url(self::url(['type' => $type, 'paged' => false])) . '"' . (self::param('type') === $type ? ' aria-current="true"' : '') . '>' . esc_html($type === 'attachment' ? 'PDFs' : $object->labels->name) . '</a></li>';
        }
        echo '</ul></nav>';
    }
    private static function google(): void {
        // The reference theme always shows this block; newer settings integrations can override this filter.
        $enabled = apply_filters('uams_search_google_enabled', (bool)get_option('uams_search_google', true));
        if (!$enabled) { return; }
        $cx = apply_filters('uams_search_google_cx', '014806496997774146681:pyl1jhwi-bo');
        echo '<section aria-label="Search all UAMS sites"><h2>Search All UAMS Sites</h2><p>Search UAMS websites on UAMS.edu and UAMShealth.com.</p><script async src="' . esc_url('https://cse.google.com/cse.js?cx=' . rawurlencode($cx)) . '"></script><div class="gcse-searchresults-only" data-queryParameterName="s"></div></section>';
    }
}
