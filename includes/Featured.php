<?php
namespace UAMS_Search;

/**
 * Featured results ("best bets"): keyword rules that put chosen posts or
 * external links in a featured box above the regular results.
 *
 * Install-wide rules live in a network option and are managed by network
 * admins. Each sub-site's rules live in that site's own option and are
 * managed by its editors. A rule matches when one of its keyword phrases
 * appears in the search, compared word by word after stemming.
 */
final class Featured {
    public const OPTION = 'uams_search_featured';
    public const MAX_ITEMS = 5;

    /** Rules for one scope: 'network' or 'site' (the current site). */
    public static function rules(string $scope): array {
        $rules = $scope === 'network' ? get_site_option(self::OPTION, []) : get_option(self::OPTION, []);
        return is_array($rules) ? array_values(array_filter($rules, 'is_array')) : [];
    }

    public static function save_rules(string $scope, array $rules): void {
        $rules = array_values($rules);
        if ($scope === 'network') { update_site_option(self::OPTION, $rules); } else { update_option(self::OPTION, $rules, false); }
    }

    /** Clean a rule from form input. Returns null when it has no keywords or no usable items. */
    public static function sanitize_rule(array $input, ?string $id = null): ?array {
        $keywords = [];
        foreach (preg_split('/[\r\n,]+/', is_scalar($input['keywords'] ?? '') ? (string)$input['keywords'] : '') as $line) {
            $line = trim(mb_substr(sanitize_text_field($line), 0, 100));
            if ($line !== '' && Text::tokens($line)) { $keywords[] = $line; }
        }
        $keywords = array_slice(array_values(array_unique($keywords)), 0, 20);
        $items = [];
        foreach (array_slice((array)($input['items'] ?? []), 0, self::MAX_ITEMS) as $raw) {
            if (!is_array($raw)) { continue; }
            $item = self::sanitize_item($raw);
            if ($item) { $items[] = $item; }
        }
        if (!$keywords || !$items) { return null; }
        return [
            'id' => $id ?: wp_generate_uuid4(),
            'keywords' => $keywords,
            'active' => !empty($input['active']),
            'items' => $items,
            'updated' => gmdate('Y-m-d H:i:s'),
        ];
    }

    private static function sanitize_item(array $raw): ?array {
        $kind = ($raw['kind'] ?? '') === 'link' ? 'link' : 'post';
        $url = esc_url_raw(trim(is_scalar($raw['url'] ?? '') ? (string)$raw['url'] : ''), ['http', 'https']);
        if ($url === '') { return null; }
        $text = static fn($key, $max) => mb_substr(sanitize_text_field(is_scalar($raw[$key] ?? '') ? (string)$raw[$key] : ''), 0, $max);
        $item = [
            'kind' => $kind, 'url' => $url,
            'title' => $text('title', 200), 'description' => mb_substr(sanitize_textarea_field(is_scalar($raw['description'] ?? '') ? (string)$raw['description'] : ''), 0, 400),
            'image' => esc_url_raw(trim(is_scalar($raw['image'] ?? '') ? (string)$raw['image'] : ''), ['http', 'https']),
            'image_alt' => $text('image_alt', 200),
        ];
        if ($kind === 'post') {
            $target = self::locate($url);
            if (!$target) { return null; }
            [$item['site'], $item['id']] = $target;
        } elseif ($item['title'] === '') {
            return null; // An external link needs its own title.
        }
        return $item;
    }

    /** Find the site and post for a URL on this install: [site_id, post_id] or null. */
    public static function locate(string $url): ?array {
        $parts = wp_parse_url($url);
        if (empty($parts['host'])) { return null; }
        $site = get_current_blog_id();
        if (is_multisite()) {
            $found = get_site_by_path($parts['host'], $parts['path'] ?? '/');
            if (!$found) { return null; }
            $site = (int)$found->blog_id;
        } elseif (strcasecmp($parts['host'], (string)wp_parse_url(home_url(), PHP_URL_HOST)) !== 0) {
            return null;
        }
        $changed = get_current_blog_id() !== $site;
        if ($changed) { switch_to_blog($site); }
        try {
            $id = url_to_postid($url);
            if (!$id && function_exists('attachment_url_to_postid')) { $id = attachment_url_to_postid($url); }
            if (!$id && untrailingslashit($url) === untrailingslashit(home_url())) { $id = (int)get_option('page_on_front'); }
        } finally { if ($changed) { restore_current_blog(); } }
        return $id ? [$site, (int)$id] : null;
    }

    /** True when a keyword phrase appears in the search, word by word after stemming. */
    public static function matches(array $search_tokens, string $keyword): bool {
        $needle = Text::tokens($keyword);
        $n = count($needle); $h = count($search_tokens);
        if (!$n || $n > $h) { return false; }
        for ($i = 0; $i <= $h - $n; $i++) {
            if (array_slice($search_tokens, $i, $n) === $needle) { return true; }
        }
        return false;
    }

    /**
     * Featured items for a search on the current site: this site's rules first,
     * then install-wide rules. Unpublished or hidden posts are skipped.
     * Each item: kind, site, id, title, url, description, image, image_alt, post_type.
     */
    public static function for_search(string $search, int $max = 3): array {
        $tokens = Text::tokens($search);
        if (!$tokens) { return []; }
        $out = []; $seen = [];
        foreach (array_merge(self::rules('site'), is_multisite() ? self::rules('network') : []) as $rule) {
            if (empty($rule['active']) || empty($rule['items'])) { continue; }
            $hit = false;
            foreach ((array)$rule['keywords'] as $keyword) { if (self::matches($tokens, (string)$keyword)) { $hit = true; break; } }
            if (!$hit) { continue; }
            foreach ((array)$rule['items'] as $item) {
                $resolved = self::resolve($item);
                if (!$resolved) { continue; }
                $key = $resolved->kind === 'post' ? $resolved->site . ':' . $resolved->id : 'link:' . $resolved->url;
                if (isset($seen[$key])) { continue; }
                $seen[$key] = true; $out[] = $resolved;
                if (count($out) >= $max) { return $out; }
            }
        }
        return $out;
    }

    private static function resolve(array $item): ?object {
        if (($item['kind'] ?? '') === 'link') {
            if (empty($item['url']) || empty($item['title'])) { return null; }
            return (object)['kind' => 'link', 'site' => 0, 'id' => 0, 'title' => $item['title'], 'url' => $item['url'], 'description' => $item['description'] ?? '', 'image' => $item['image'] ?? '', 'image_alt' => $item['image_alt'] ?? '', 'post_type' => ''];
        }
        $site = (int)($item['site'] ?? 0); $id = (int)($item['id'] ?? 0);
        if (!$site || !$id || (is_multisite() && !in_array($site, Config::public_sites(), true))) { return null; }
        $changed = get_current_blog_id() !== $site;
        if ($changed) { switch_to_blog($site); }
        try {
            $post = get_post($id);
            if (!$post || $post->post_password !== '' || get_post_meta($id, '_uams_search_exclude', true)) { return null; }
            $visible = $post->post_type === 'attachment' ? in_array($post->post_status, ['inherit', 'publish'], true) : $post->post_status === 'publish';
            if (!$visible || ($post->post_type === 'provider' && get_post_meta($id, 'physician_hidden', true)) || ($post->post_type === 'location' && get_post_meta($id, 'location_hidden', true))) { return null; }
            $description = (string)($item['description'] ?? '');
            if ($description === '') {
                $description = has_excerpt($post) ? $post->post_excerpt : wp_trim_words(Text::plain(strip_shortcodes($post->post_content)), 30, '…');
            }
            $image = (string)($item['image'] ?? ''); $alt = (string)($item['image_alt'] ?? '');
            if ($image === '' && ($thumb = get_post_thumbnail_id($post))) {
                $src = wp_get_attachment_image_src($thumb, 'medium');
                if ($src) { $image = $src[0]; $alt = ''; }
            }
            return (object)[
                'kind' => 'post', 'site' => $site, 'id' => $id,
                'title' => ($item['title'] ?? '') !== '' ? $item['title'] : Text::plain(get_the_title($post)),
                'url' => $post->post_type === 'attachment' ? (string)wp_get_attachment_url($id) : (string)get_permalink($post),
                'description' => Text::plain($description), 'image' => $image, 'image_alt' => $alt, 'post_type' => $post->post_type,
            ];
        } finally { if ($changed) { restore_current_blog(); } }
    }
}
