<?php

namespace UAMS_Search;

use WP_REST_Request;

final class Integration
{
    public static array $excerpts = [];

    public static function param(string $name, string $default = ''): string
    {
        return isset($_GET[$name]) && is_scalar($_GET[$name]) ? sanitize_text_field(wp_unslash((string) $_GET[$name])) : $default;
    }

    public static function active(): bool
    {
        if (! DB::ready()) {
            return false;
        }

        if (is_user_logged_in() && self::param('engine') === 'wordpress') {
            return false;
        }

        return Config::flag('enabled') || (is_user_logged_in() && self::param('engine') === 'uams');
    }

    public static function args(): array
    {
        return ['s' => get_search_query(false), 'site' => get_current_blog_id(), 'scope' => Config::scope(self::param('scope')), 'type' => self::param('type'), 'page' => max(1, (int) get_query_var('paged', 1)), 'per_page' => Config::page_size()];
    }

    public static function hooks(): void
    {
        add_filter('posts_pre_query', [self::class, 'posts'], 1, 2);
        add_filter('relevanssi_search_ok', [self::class, 'allow_other_engine'], PHP_INT_MAX, 2);
        add_filter('asp_query_is_search', [self::class, 'allow_other_engine'], PHP_INT_MAX, 2);
        add_filter('template_include', [self::class, 'template'], 99);
        add_filter('get_the_excerpt', [self::class, 'excerpt'], 99, 2);
        add_filter('get_search_form', [self::class, 'form'], 99);
        add_action('wp_enqueue_scripts', [self::class, 'assets']);
        add_action('rest_api_init', [self::class, 'rest']);
        add_shortcode('uams_search_form', static fn (): string => (string) get_search_form(false));
    }

    public static function allow_other_engine(mixed $allowed, mixed $query): mixed
    {
        // Only yield a query we already answered. Other engines keep normal searches,
        // secondary queries, and the error/missing-index fallback they handled before.
        return $query instanceof \WP_Query && is_array($query->get('uams_search_results')) ? false : $allowed;
    }

    public static function posts(mixed $posts, \WP_Query $query): mixed
    {
        if ($posts !== null || ! $query->is_main_query() || ! $query->is_search() || ! in_array($query->get('fields'), ['', 'all'], true) || is_admin() || ! self::active()) {
            return $posts;
        }

        // All UAMS websites is answered by Google on the page itself, so the plugin does no search for it.
        if (Config::scope(self::param('scope')) === 'all') {
            $query->found_posts = 0;
            $query->max_num_pages = 0;
            $query->set('uams_search_results', ['results' => [], 'total' => 0, 'pages' => 0, 'fallback' => false, 'suggestion' => null, 'google' => true]);

            return [];
        }

        try {
            $args = ['s' => (string) $query->get('s'), 'site' => get_current_blog_id(), 'scope' => Config::scope(self::param('scope')), 'type' => self::param('type'), 'page' => max(1, (int) $query->get('paged')), 'per_page' => Config::page_size($query->query['posts_per_page'] ?? null)];
            $results = Query::run($args);
            $out = [];
            foreach ($results['results'] as $result) {
                $changed = get_current_blog_id() !== $result->site;
                if ($changed) {
                    switch_to_blog($result->site);
                }

                try {
                    $post = get_post($result->id);
                    if ($post) {
                        $post = clone $post;
                        $post->id = $result->id;
                        $post->site = $result->site;
                        $post->blog_id = $result->site;
                        $post->relevance = $result->relevance;
                        $post->excerpt = $result->excerpt;
                        $out[] = $post;
                        self::$excerpts[$result->site.':'.$result->id] = $result->excerpt;
                    }
                } finally {
                    if ($changed) {
                        restore_current_blog();
                    }
                }
            }

            $query->set('posts_per_page', $args['per_page']);
            $query->found_posts = $results['total'];
            $query->max_num_pages = $results['pages'];
            // WordPress' ordinary post cache is keyed by local ID only. Cross-site IDs must never enter it.
            $query->set('cache_results', false);
            $query->set('update_post_meta_cache', false);
            $query->set('update_post_term_cache', false);
            $query->set('uams_search_results', $results);

            return $out;
        } catch (\Throwable $throwable) {
            error_log('UAMS Search: search failed; using WordPress. '.$throwable->getMessage());

            return $posts;
        }
    }

    public static function template(string $template): string
    {
        global $wp_query;
        if (! is_search() || ! self::active() || ! $wp_query->get('uams_search_results')) {
            return $template;
        }

        if (get_template() === 'genesis' || get_stylesheet() === 'uams-2020') {
            return __DIR__.'/../templates/search-genesis.php';
        }

        return Filters::string('uams_search_template', __DIR__.'/../templates/search.php', $template);
    }

    public static function excerpt(mixed $excerpt, mixed $post): mixed
    {
        if (! $post || ! is_search() || ! self::active()) {
            return $excerpt;
        }

        return self::$excerpts[($post->site ?? get_current_blog_id()).':'.$post->ID] ?? $excerpt;
    }

    public static function form(string $form): string
    {
        if (! self::active()) {
            return $form;
        }

        $id = wp_unique_id('uams-search-');
        $scope = Config::scope(self::param('scope'));
        $html = '<form role="search" method="get" action="'.esc_url(home_url('/')).'" class="uams-search-form"><label for="'.esc_attr($id).'">Search This Website</label><div class="uams-search-input"><input type="search" name="s" id="'.esc_attr($id).'" value="'.esc_attr(get_search_query(false)).'" maxlength="200" autocomplete="off" data-uams-live="'.(Config::flag('live') ? 'true' : 'false').'"><button type="submit">Search</button></div><input type="hidden" name="scope" value="'.esc_attr($scope).'">';
        if (is_user_logged_in() && self::param('engine') === 'uams') {
            $html .= '<input type="hidden" name="engine" value="uams">';
        }

        if (isset(Config::section('types')[self::param('type')])) {
            $html .= '<input type="hidden" name="type" value="'.esc_attr(self::param('type')).'">';
        }

        return $html.'</form>';
    }

    public static function assets(): void
    {
        if (! self::active()) {
            return;
        }

        wp_enqueue_style('uams-search', plugins_url('assets/search.css', UAMS_SEARCH_FILE), [], UAMS_SEARCH_VERSION);
        if (Config::flag('live')) {
            wp_enqueue_script('uams-search', plugins_url('assets/live-search.js', UAMS_SEARCH_FILE), [], UAMS_SEARCH_VERSION, true);
            wp_localize_script('uams-search', 'UAMSSearch', ['endpoint' => rest_url('uams-search/v1/suggest'), 'nonce' => is_user_logged_in() ? wp_create_nonce('wp_rest') : '', 'preview' => is_user_logged_in() && self::param('engine') === 'uams']);
        }
    }

    public static function rest(): void
    {
        register_rest_route('uams-search/v1', '/suggest', [
            'methods' => 'GET',
            'permission_callback' => static fn (WP_REST_Request $request): bool => DB::ready() && Config::flag('live') && (Config::flag('enabled') || (is_user_logged_in() && $request->get_param('engine') === 'uams')),
            'args' => [
                's' => ['required' => true, 'type' => 'string', 'maxLength' => 200, 'minLength' => 2, 'sanitize_callback' => 'sanitize_text_field'],
                'scope' => ['type' => 'string', 'enum' => ['site', 'install', 'all']],
                'type' => ['type' => 'string', 'sanitize_callback' => 'sanitize_key'],
                'engine' => ['type' => 'string', 'enum' => ['uams']],
            ],
            'callback' => static function (WP_REST_Request $request): \WP_REST_Response|\WP_Error {
                try {
                    $out = Query::run(['s' => $request['s'], 'site' => get_current_blog_id(), 'scope' => Config::scope($request->get_param('scope')), 'type' => $request->get_param('type') ?: '', 'per_page' => 6, 'suggest' => false]);
                    $items = array_map(static fn (object $r): array => ['id' => $r->id, 'site' => $r->site, 'title' => $r->title.($r->post_type === 'attachment' ? ' (PDF, '.size_format($r->file_size).')' : ''), 'url' => esc_url_raw($r->url)], $out['results']);
                    $response = new \WP_REST_Response(['items' => $items, 'total' => $out['total']], 200);
                    $response->header('Cache-Control', 'no-store');

                    return $response;
                } catch (\Throwable $throwable) {
                    error_log('UAMS Search: REST '.$throwable->getMessage());

                    return new \WP_Error('uams_search_unavailable', 'Search suggestions are unavailable.', ['status' => 503]);
                }
            },
        ]);
    }

    public static function render(): void
    {
        global $wp_query;
        $out = $wp_query->get('uams_search_results');
        if (! $out) {
            return;
        }

        echo '<section class="uams-search-results" aria-label="Search results">';
        echo '<h1>Search Results for: '.esc_html(get_search_query(false)).'</h1>';
        echo self::form('');
        self::filters(! empty($out['google']));
        if (! empty($out['google'])) {
            self::google();
            echo '</section>';

            return;
        }

        echo '<h2>'.esc_html(number_format_i18n($out['total']).' results').'</h2>';
        if ($out['fallback']) {
            echo '<p>Not every word matched. These results match some of your words.</p>';
        }

        if ($out['suggestion']) {
            echo '<p>Did you mean <a href="'.esc_url(self::url(['s' => $out['suggestion'], 'paged' => false])).'">'.esc_html($out['suggestion']).'</a>?</p>';
        }

        if (! $out['total']) {
            echo '<p>No results found. Try fewer words or check the spelling.</p>';
            if ((int) get_query_var('paged', 1) <= 1) {
                Misses::record(get_search_query(false));
            }
        }

        $card_group = null;
        foreach ($out['results'] as $result) {
            $type = $result->post_type;
            if ($type === 'provider' || $type === 'location') {
                $card = $type === 'provider' ? ProviderCard::data($result) : LocationCard::data($result);
                if (! $card) {
                    continue;
                }

                if ($card_group !== $type) {
                    if ($card_group !== null) {
                        echo '</div>';
                    }

                    echo $type === 'provider' ? '<div class="uams-search-provider-cards card-list card-list-doctors">' : '<div class="uams-search-location-cards card-list">';
                    $card_group = $type;
                }

                if ($type === 'provider') {
                    ProviderCard::render($card);
                } else {
                    LocationCard::render($card);
                }

                continue;
            }

            if ($card_group !== null) {
                echo '</div>';
                $card_group = null;
            }

            $label = Config::result_labels()[$result->post_type] ?? '';
            echo '<article class="uams-search-result">'.($label !== '' ? '<p class="uams-search-label">'.esc_html($label).'</p>' : '').'<h3><a href="'.esc_url($result->url).'">'.esc_html($result->title ?: 'Untitled');
            if ($result->post_type === 'attachment') {
                echo ' <span>('.esc_html(size_format($result->file_size)).')</span>';
            }

            echo '</a></h3><p>'.wp_kses($result->excerpt, ['mark' => []]).'</p></article>';
        }

        if ($card_group !== null) {
            echo '</div>';
        }

        // paginate_links expects a literal placeholder in base.
        if ($out['pages'] > 1) {
            $links = paginate_links(['base' => str_replace('999999999', '%#%', self::url(['paged' => 999999999])), 'current' => max(1, (int) get_query_var('paged', 1)), 'total' => $out['pages'], 'type' => 'list']);
            echo '<nav aria-label="Search result pages">'.wp_kses_post($links).'</nav>';
        }

        echo '</section>';
    }

    public static function url(array $changes = []): string
    {
        $args = ['s' => get_search_query(false), 'scope' => Config::scope(self::param('scope'))];
        if (self::param('type')) {
            $args['type'] = self::param('type');
        }

        if (is_user_logged_in() && self::param('engine') === 'uams') {
            $args['engine'] = 'uams';
        }

        return add_query_arg(array_merge($args, $changes), home_url('/'));
    }

    private static function filters(bool $google = false): void
    {
        echo '<nav aria-label="Search scope"><ul class="uams-search-filters">';
        foreach (Config::scope_options() as $scope => $label) {
            // The note is inside the link, so it is part of the link's name.
            $note = $scope === 'all' ? ' <span class="uams-search-note">UAMS.edu and UAMSHealth.com</span>' : '';
            echo '<li><a href="'.esc_url(self::url(['scope' => $scope, 'paged' => false])).'"'.(Config::scope(self::param('scope')) === $scope ? ' aria-current="true"' : '').'>'.esc_html($label).$note.'</a></li>';
        }

        echo '</ul></nav>';
        // The plugin's content types do not apply to Google's results.
        if ($google) {
            return;
        }

        echo '<nav aria-label="Result type"><ul class="uams-search-filters"><li><a href="'.esc_url(self::url(['type' => false, 'paged' => false])).'"'.(self::param('type') === '' ? ' aria-current="true"' : '').'>All results</a></li>';
        foreach (Config::section('types') as $type => $boost) {
            $object = get_post_type_object($type);
            if (! $object || ($type === 'attachment' && ! Config::flag('pdf'))) {
                continue;
            }

            echo '<li><a href="'.esc_url(self::url(['type' => $type, 'paged' => false])).'"'.(self::param('type') === $type ? ' aria-current="true"' : '').'>'.esc_html((string) (Config::type_labels()[$type] ?? $object->labels->name)).'</a></li>';
        }

        echo '</ul></nav>';
    }

    private static function google(): void
    {
        $cx = Filters::string('uams_search_google_cx', '014806496997774146681:pyl1jhwi-bo');
        echo '<section aria-label="All UAMS websites"><h2>All UAMS Websites</h2><script async src="'.esc_url('https://cse.google.com/cse.js?cx='.rawurlencode($cx)).'"></script><div class="gcse-searchresults-only" data-queryParameterName="s"></div></section>';
    }
}
