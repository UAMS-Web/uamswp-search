<?php

/**
 * Typed access to the stand-in state the unit tests keep in `$GLOBALS`.
 */

declare(strict_types=1);

namespace UAMS_Search\Tests\Support;

use UnexpectedValueException;

/**
 * One typed getter and setter per stand-in key, so a test reads and writes the state without an untyped `$GLOBALS` access.
 *
 * Keys keep the names the stand-ins in `tests/bootstrap.php` and the test files already use (`uams_test_*`, the preview scripts' `uams_preview_*`, and the real globals `menu`, `pagenow`, `wpdb` and `wp_query`), so an unconverted stand-in and a converted test see the same state.
 *
 * A getter answers the documented default while its key is absent, and a nullable one answers null. A key that holds a value of another container type throws `UnexpectedValueException` naming the key, so a test that stored the wrong shape fails where it is read. Only the container (array, bool, string, int, object) is checked: the element types are the ones the setters declare, and an unconverted stand-in that writes the key directly is trusted.
 */
final class StandIn
{
    /** Every key this class reads and writes, so `forget_all()` can clear them between tests. */
    private const KEYS = [
        'menu',
        'pagenow',
        'uams_preview_fields',
        'uams_preview_post_titles',
        'uams_preview_post_types',
        'uams_preview_results',
        'uams_preview_thumbnails',
        'uams_test_acf',
        'uams_test_acf_fields',
        'uams_test_acf_lookups',
        'uams_test_attached',
        'uams_test_autosaves',
        'uams_test_blocks',
        'uams_test_blog_calls',
        'uams_test_blog_lookups',
        'uams_test_blog_names',
        'uams_test_caps',
        'uams_test_cleared',
        'uams_test_cron',
        'uams_test_enqueue_args',
        'uams_test_enqueued',
        'uams_test_environment',
        'uams_test_filters',
        'uams_test_get_term',
        'uams_test_hidden_types',
        'uams_test_hook_args',
        'uams_test_hooks',
        'uams_test_localized',
        'uams_test_logged_in',
        'uams_test_main_site',
        'uams_test_memory_bytes',
        'uams_test_menu',
        'uams_test_meta',
        'uams_test_meta_reads',
        'uams_test_meta_record',
        'uams_test_multisite',
        'uams_test_network_admin',
        'uams_test_nonce_checks',
        'uams_test_options',
        'uams_test_post_types',
        'uams_test_post_types_args',
        'uams_test_posts',
        'uams_test_posts_default',
        'uams_test_redirect',
        'uams_test_revisions',
        'uams_test_scheduled',
        'uams_test_site_details',
        'uams_test_site_ids',
        'uams_test_sites',
        'uams_test_sites_args',
        'uams_test_sites_asked',
        'uams_test_sites_return',
        'uams_test_subdomain',
        'uams_test_taxonomies',
        'uams_test_term_args',
        'uams_test_term_calls',
        'uams_test_term_meta',
        'uams_test_term_objects',
        'uams_test_terms',
        'uams_test_timezone',
        'uams_test_types',
        'uams_test_uploads',
        'uams_test_viewable_statuses',
        'wp_query',
        'wpdb',
    ];

    /** Whether the ACF stand-in answers (`uams_test_acf`); false when a test has not turned it on. */
    public static function acf_enabled(): bool
    {
        return self::bool_at('uams_test_acf', false);
    }

    public static function set_acf_enabled(bool $value): void
    {
        $GLOBALS['uams_test_acf'] = $value;
    }

    /** Whether the signed-in stand-in answers true; signed out unless a test says otherwise. */
    public static function logged_in(): bool
    {
        return self::bool_at('uams_test_logged_in', false);
    }

    public static function set_logged_in(bool $value): void
    {
        $GLOBALS['uams_test_logged_in'] = $value;
    }

    /** Whether the current site is the main site; true unless a test says otherwise. */
    public static function main_site(): bool
    {
        return self::bool_at('uams_test_main_site', true);
    }

    public static function set_main_site(bool $value): void
    {
        $GLOBALS['uams_test_main_site'] = $value;
    }

    /** Whether the install uses subdomains; false unless a test says otherwise. */
    public static function subdomain(): bool
    {
        return self::bool_at('uams_test_subdomain', false);
    }

    public static function set_subdomain(bool $value): void
    {
        $GLOBALS['uams_test_subdomain'] = $value;
    }

    /** Whether the install is a multisite; false unless a test says otherwise. */
    public static function multisite(): bool
    {
        return self::bool_at('uams_test_multisite', false);
    }

    public static function set_multisite(bool $value): void
    {
        $GLOBALS['uams_test_multisite'] = $value;
    }

    /** Whether the request is in the network admin; false unless a test says otherwise. */
    public static function network_admin(): bool
    {
        return self::bool_at('uams_test_network_admin', false);
    }

    public static function set_network_admin(bool $value): void
    {
        $GLOBALS['uams_test_network_admin'] = $value;
    }

    /** Whether the meta stand-ins record the reads they answer. */
    public static function meta_record(): bool
    {
        return self::bool_at('uams_test_meta_record', false);
    }

    public static function set_meta_record(bool $value): void
    {
        $GLOBALS['uams_test_meta_record'] = $value;
    }

    /** The environment type the stand-in reports. */
    public static function environment(): string
    {
        return self::string_at('uams_test_environment', 'production');
    }

    public static function set_environment(string $value): void
    {
        $GLOBALS['uams_test_environment'] = $value;
    }

    /** The timezone name the stand-in reports. */
    public static function timezone(): string
    {
        return self::string_at('uams_test_timezone', 'UTC');
    }

    public static function set_timezone(string $value): void
    {
        $GLOBALS['uams_test_timezone'] = $value;
    }

    /** The admin page file WordPress keeps in the real global `$pagenow`. */
    public static function pagenow(): string
    {
        return self::string_at('pagenow', '');
    }

    public static function set_pagenow(string $value): void
    {
        $GLOBALS['pagenow'] = $value;
    }

    /** The path the attachment stand-in answers, or null when a test has not set one. */
    public static function attached(): ?string
    {
        return self::nullable_string_at('uams_test_attached');
    }

    public static function set_attached(?string $value): void
    {
        $GLOBALS['uams_test_attached'] = $value;
    }

    /** The uploads base directory the stand-in answers, or null when a test has not set one. */
    public static function uploads(): ?string
    {
        return self::nullable_string_at('uams_test_uploads');
    }

    public static function set_uploads(?string $value): void
    {
        $GLOBALS['uams_test_uploads'] = $value;
    }

    /** The URL the last intercepted redirect went to, or null when none did. */
    public static function redirect(): ?string
    {
        return self::nullable_string_at('uams_test_redirect');
    }

    public static function set_redirect(?string $value): void
    {
        $GLOBALS['uams_test_redirect'] = $value;
    }

    /**
     * The selectors the ACF field stand-in was asked for, in order.
     *
     * @return list<string>
     */
    public static function acf_lookups(): array
    {
        /** @var list<string> $value */
        $value = self::array_at('uams_test_acf_lookups');

        return $value;
    }

    /** @param list<string> $value */
    public static function set_acf_lookups(array $value): void
    {
        $GLOBALS['uams_test_acf_lookups'] = $value;
    }

    public static function add_acf_lookup(string $item): void
    {
        $list = self::array_at('uams_test_acf_lookups');
        $list[] = $item;
        $GLOBALS['uams_test_acf_lookups'] = $list;
    }

    /**
     * The post IDs the autosave stand-in treats as autosaves.
     *
     * @return list<int>
     */
    public static function autosaves(): array
    {
        /** @var list<int> $value */
        $value = self::array_at('uams_test_autosaves');

        return $value;
    }

    /** @param list<int> $value */
    public static function set_autosaves(array $value): void
    {
        $GLOBALS['uams_test_autosaves'] = $value;
    }

    public static function add_autosave(int $item): void
    {
        $list = self::array_at('uams_test_autosaves');
        $list[] = $item;
        $GLOBALS['uams_test_autosaves'] = $list;
    }

    /**
     * The site IDs the blog-details stand-in was asked for, in order.
     *
     * @return list<int>
     */
    public static function blog_lookups(): array
    {
        /** @var list<int> $value */
        $value = self::array_at('uams_test_blog_lookups');

        return $value;
    }

    /** @param list<int> $value */
    public static function set_blog_lookups(array $value): void
    {
        $GLOBALS['uams_test_blog_lookups'] = $value;
    }

    public static function add_blog_lookup(int $item): void
    {
        $list = self::array_at('uams_test_blog_lookups');
        $list[] = $item;
        $GLOBALS['uams_test_blog_lookups'] = $list;
    }

    /**
     * The capabilities the current user holds.
     *
     * @return list<string>
     */
    public static function caps(): array
    {
        /** @var list<string> $value */
        $value = self::array_at('uams_test_caps');

        return $value;
    }

    /** @param list<string> $value */
    public static function set_caps(array $value): void
    {
        $GLOBALS['uams_test_caps'] = $value;
    }

    public static function add_capability(string $item): void
    {
        $list = self::array_at('uams_test_caps');
        $list[] = $item;
        $GLOBALS['uams_test_caps'] = $list;
    }

    /**
     * The cron hooks cleared, in order.
     *
     * @return list<string>
     */
    public static function cleared(): array
    {
        /** @var list<string> $value */
        $value = self::array_at('uams_test_cleared');

        return $value;
    }

    /** @param list<string> $value */
    public static function set_cleared(array $value): void
    {
        $GLOBALS['uams_test_cleared'] = $value;
    }

    public static function add_cleared_hook(string $item): void
    {
        $list = self::array_at('uams_test_cleared');
        $list[] = $item;
        $GLOBALS['uams_test_cleared'] = $list;
    }

    /**
     * The handles enqueued, in order.
     *
     * @return list<string>
     */
    public static function enqueued(): array
    {
        /** @var list<string> $value */
        $value = self::array_at('uams_test_enqueued');

        return $value;
    }

    /** @param list<string> $value */
    public static function set_enqueued(array $value): void
    {
        $GLOBALS['uams_test_enqueued'] = $value;
    }

    public static function add_enqueued(string $item): void
    {
        $list = self::array_at('uams_test_enqueued');
        $list[] = $item;
        $GLOBALS['uams_test_enqueued'] = $list;
    }

    /**
     * The post types the stand-in reports as not viewable.
     *
     * @return list<string>
     */
    public static function hidden_types(): array
    {
        /** @var list<string> $value */
        $value = self::array_at('uams_test_hidden_types');

        return $value;
    }

    /** @param list<string> $value */
    public static function set_hidden_types(array $value): void
    {
        $GLOBALS['uams_test_hidden_types'] = $value;
    }

    public static function add_hidden_type(string $item): void
    {
        $list = self::array_at('uams_test_hidden_types');
        $list[] = $item;
        $GLOBALS['uams_test_hidden_types'] = $list;
    }

    /**
     * The post IDs the revision stand-in treats as revisions.
     *
     * @return list<int>
     */
    public static function revisions(): array
    {
        /** @var list<int> $value */
        $value = self::array_at('uams_test_revisions');

        return $value;
    }

    /** @param list<int> $value */
    public static function set_revisions(array $value): void
    {
        $GLOBALS['uams_test_revisions'] = $value;
    }

    public static function add_revision(int $item): void
    {
        $list = self::array_at('uams_test_revisions');
        $list[] = $item;
        $GLOBALS['uams_test_revisions'] = $list;
    }

    /**
     * The post statuses the stand-in reports as viewable; `publish` alone until a test says otherwise.
     *
     * @return list<string>
     */
    public static function viewable_statuses(): array
    {
        /** @var list<string> $value */
        $value = self::array_at('uams_test_viewable_statuses', ['publish']);

        return $value;
    }

    /** @param list<string> $value */
    public static function set_viewable_statuses(array $value): void
    {
        $GLOBALS['uams_test_viewable_statuses'] = $value;
    }

    public static function add_viewable_status(string $item): void
    {
        $list = self::array_at('uams_test_viewable_statuses', ['publish']);
        $list[] = $item;
        $GLOBALS['uams_test_viewable_statuses'] = $list;
    }

    /**
     * The IDs the term stand-in was asked for, in order.
     *
     * @return list<int|string>
     */
    public static function term_calls(): array
    {
        /** @var list<int|string> $value */
        $value = self::array_at('uams_test_term_calls');

        return $value;
    }

    /** @param list<int|string> $value */
    public static function set_term_calls(array $value): void
    {
        $GLOBALS['uams_test_term_calls'] = $value;
    }

    public static function add_term_call(int|string $item): void
    {
        $list = self::array_at('uams_test_term_calls');
        $list[] = $item;
        $GLOBALS['uams_test_term_calls'] = $list;
    }

    /**
     * The argument arrays the site-list stand-in was called with, in order.
     *
     * @return list<array<string, mixed>>
     */
    public static function sites_args(): array
    {
        /** @var list<array<string, mixed>> $value */
        $value = self::array_at('uams_test_sites_args');

        return $value;
    }

    /** @param list<array<string, mixed>> $value */
    public static function set_sites_args(array $value): void
    {
        $GLOBALS['uams_test_sites_args'] = $value;
    }

    /** @param array<string, mixed> $item */
    public static function add_sites_args(array $item): void
    {
        $list = self::array_at('uams_test_sites_args');
        $list[] = $item;
        $GLOBALS['uams_test_sites_args'] = $list;
    }

    /**
     * The argument arrays the namespaced site-list shim was called with, in order.
     *
     * @return list<array<string, mixed>>
     */
    public static function sites_asked(): array
    {
        /** @var list<array<string, mixed>> $value */
        $value = self::array_at('uams_test_sites_asked');

        return $value;
    }

    /** @param list<array<string, mixed>> $value */
    public static function set_sites_asked(array $value): void
    {
        $GLOBALS['uams_test_sites_asked'] = $value;
    }

    /** @param array<string, mixed> $item */
    public static function add_sites_asked(array $item): void
    {
        $list = self::array_at('uams_test_sites_asked');
        $list[] = $item;
        $GLOBALS['uams_test_sites_asked'] = $list;
    }

    /**
     * The taxonomy and arguments each term-list call received, in order.
     *
     * @return list<array{string, array<string, mixed>}>
     */
    public static function term_args(): array
    {
        /** @var list<array{string, array<string, mixed>}> $value */
        $value = self::array_at('uams_test_term_args');

        return $value;
    }

    /** @param list<array{string, array<string, mixed>}> $value */
    public static function set_term_args(array $value): void
    {
        $GLOBALS['uams_test_term_args'] = $value;
    }

    /** @param array{string, array<string, mixed>} $item */
    public static function add_term_args(array $item): void
    {
        $list = self::array_at('uams_test_term_args');
        $list[] = $item;
        $GLOBALS['uams_test_term_args'] = $list;
    }

    /**
     * The arguments of each nonce check, in order.
     *
     * @return list<list<mixed>>
     */
    public static function nonce_checks(): array
    {
        /** @var list<list<mixed>> $value */
        $value = self::array_at('uams_test_nonce_checks');

        return $value;
    }

    /** @param list<list<mixed>> $value */
    public static function set_nonce_checks(array $value): void
    {
        $GLOBALS['uams_test_nonce_checks'] = $value;
    }

    /** @param list<mixed> $item */
    public static function add_nonce_check(array $item): void
    {
        $list = self::array_at('uams_test_nonce_checks');
        $list[] = $item;
        $GLOBALS['uams_test_nonce_checks'] = $list;
    }

    /**
     * The menu registration calls, each as the function name followed by its arguments.
     *
     * @return list<list<mixed>>
     */
    public static function menu_calls(): array
    {
        /** @var list<list<mixed>> $value */
        $value = self::array_at('uams_test_menu');

        return $value;
    }

    /** @param list<list<mixed>> $value */
    public static function set_menu_calls(array $value): void
    {
        $GLOBALS['uams_test_menu'] = $value;
    }

    /** @param list<mixed> $item */
    public static function add_menu_call(array $item): void
    {
        $list = self::array_at('uams_test_menu');
        $list[] = $item;
        $GLOBALS['uams_test_menu'] = $list;
    }

    /**
     * The cron events scheduled, each as hook, recurrence and time.
     *
     * @return list<list<mixed>>
     */
    public static function scheduled(): array
    {
        /** @var list<list<mixed>> $value */
        $value = self::array_at('uams_test_scheduled');

        return $value;
    }

    /** @param list<list<mixed>> $value */
    public static function set_scheduled(array $value): void
    {
        $GLOBALS['uams_test_scheduled'] = $value;
    }

    /** @param list<mixed> $item */
    public static function add_scheduled(array $item): void
    {
        $list = self::array_at('uams_test_scheduled');
        $list[] = $item;
        $GLOBALS['uams_test_scheduled'] = $list;
    }

    /**
     * The full arguments of each script or style enqueued, in order.
     *
     * @return list<list<mixed>>
     */
    public static function enqueue_args(): array
    {
        /** @var list<list<mixed>> $value */
        $value = self::array_at('uams_test_enqueue_args');

        return $value;
    }

    /** @param list<list<mixed>> $value */
    public static function set_enqueue_args(array $value): void
    {
        $GLOBALS['uams_test_enqueue_args'] = $value;
    }

    /** @param list<mixed> $item */
    public static function add_enqueue_args(array $item): void
    {
        $list = self::array_at('uams_test_enqueue_args');
        $list[] = $item;
        $GLOBALS['uams_test_enqueue_args'] = $list;
    }

    /**
     * The handle, object name and data of each script localization, in order.
     *
     * @return list<list<mixed>>
     */
    public static function localized(): array
    {
        /** @var list<list<mixed>> $value */
        $value = self::array_at('uams_test_localized');

        return $value;
    }

    /** @param list<list<mixed>> $value */
    public static function set_localized(array $value): void
    {
        $GLOBALS['uams_test_localized'] = $value;
    }

    /** @param list<mixed> $item */
    public static function add_localized(array $item): void
    {
        $list = self::array_at('uams_test_localized');
        $list[] = $item;
        $GLOBALS['uams_test_localized'] = $list;
    }

    /**
     * The key and single-value flag of each recorded meta read, in order.
     *
     * @return list<array{string, bool}>
     */
    public static function meta_reads(): array
    {
        /** @var list<array{string, bool}> $value */
        $value = self::array_at('uams_test_meta_reads');

        return $value;
    }

    /** @param list<array{string, bool}> $value */
    public static function set_meta_reads(array $value): void
    {
        $GLOBALS['uams_test_meta_reads'] = $value;
    }

    /** @param array{string, bool} $item */
    public static function add_meta_read(array $item): void
    {
        $list = self::array_at('uams_test_meta_reads');
        $list[] = $item;
        $GLOBALS['uams_test_meta_reads'] = $list;
    }

    /**
     * The parsed blocks the block parser stand-in answers, or null when a test has not set any.
     *
     * @return list<array<string, mixed>>|null
     */
    public static function blocks(): ?array
    {
        /** @var list<array<string, mixed>>|null $value */
        $value = self::array_or_null('uams_test_blocks');

        return $value;
    }

    /** @param list<array<string, mixed>> $value */
    public static function set_blocks(array $value): void
    {
        $GLOBALS['uams_test_blocks'] = $value;
    }

    /**
     * The switch and restore calls recorded, or null while recording is off.
     *
     * @return list<list<int|string>>|null
     */
    public static function blog_calls(): ?array
    {
        /** @var list<list<int|string>>|null $value */
        $value = self::array_or_null('uams_test_blog_calls');

        return $value;
    }

    /** @param list<list<int|string>> $value */
    public static function set_blog_calls(array $value): void
    {
        $GLOBALS['uams_test_blog_calls'] = $value;
    }

    /** @param list<int|string> $item */
    public static function add_blog_call(array $item): void
    {
        $list = self::array_or_null('uams_test_blog_calls') ?? [];
        $list[] = $item;
        $GLOBALS['uams_test_blog_calls'] = $list;
    }

    /**
     * The site IDs the site-list stand-in returns in place of its own, or null when a test has not set any.
     *
     * @return list<int|string>|null
     */
    public static function sites_return(): ?array
    {
        /** @var list<int|string>|null $value */
        $value = self::array_or_null('uams_test_sites_return');

        return $value;
    }

    /** @param list<int|string> $value */
    public static function set_sites_return(array $value): void
    {
        $GLOBALS['uams_test_sites_return'] = $value;
    }

    /**
     * The results the preview scripts render.
     *
     * @return list<object>
     */
    public static function preview_results(): array
    {
        /** @var list<object> $value */
        $value = self::array_at('uams_preview_results');

        return $value;
    }

    /** @param list<object> $value */
    public static function set_preview_results(array $value): void
    {
        $GLOBALS['uams_preview_results'] = $value;
    }

    /**
     * The admin menu WordPress keeps in the real global `$menu`.
     *
     * @return list<list<string>>
     */
    public static function menu(): array
    {
        /** @var list<list<string>> $value */
        $value = self::array_at('menu');

        return $value;
    }

    /** @param list<list<string>> $value */
    public static function set_menu(array $value): void
    {
        $GLOBALS['menu'] = $value;
    }

    /**
     * The option store behind `get_option()` and its stand-ins.
     *
     * @return array<string, mixed>
     */
    public static function options(): array
    {
        /** @var array<string, mixed> $value */
        $value = self::array_at('uams_test_options');

        return $value;
    }

    /** @param array<string, mixed> $value */
    public static function set_options(array $value): void
    {
        $GLOBALS['uams_test_options'] = $value;
    }

    public static function put_option(string $name, mixed $item): void
    {
        $map = self::array_at('uams_test_options');
        $map[$name] = $item;
        $GLOBALS['uams_test_options'] = $map;
    }

    /**
     * The callbacks `apply_filters()` runs, per filter name.
     *
     * @return array<string, list<callable>>
     */
    public static function filters(): array
    {
        /** @var array<string, list<callable>> $value */
        $value = self::array_at('uams_test_filters');

        return $value;
    }

    /** @param array<string, list<callable>> $value */
    public static function set_filters(array $value): void
    {
        $GLOBALS['uams_test_filters'] = $value;
    }

    /**
     * The callbacks registered per hook name.
     *
     * @return array<string, list<callable>>
     */
    public static function hooks(): array
    {
        /** @var array<string, list<callable>> $value */
        $value = self::array_at('uams_test_hooks');

        return $value;
    }

    /** @param array<string, list<callable>> $value */
    public static function set_hooks(array $value): void
    {
        $GLOBALS['uams_test_hooks'] = $value;
    }

    /**
     * The callback, priority and argument count registered per hook name.
     *
     * @return array<string, list<list<mixed>>>
     */
    public static function hook_args(): array
    {
        /** @var array<string, list<list<mixed>>> $value */
        $value = self::array_at('uams_test_hook_args');

        return $value;
    }

    /** @param array<string, list<list<mixed>>> $value */
    public static function set_hook_args(array $value): void
    {
        $GLOBALS['uams_test_hook_args'] = $value;
    }

    /**
     * The post meta, per post ID and key.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function meta(): array
    {
        /** @var array<int, array<string, mixed>> $value */
        $value = self::array_at('uams_test_meta');

        return $value;
    }

    /** @param array<int, array<string, mixed>> $value */
    public static function set_meta(array $value): void
    {
        $GLOBALS['uams_test_meta'] = $value;
    }

    /** @param array<string, mixed> $item */
    public static function put_meta_row(int $id, array $item): void
    {
        $map = self::array_at('uams_test_meta');
        $map[$id] = $item;
        $GLOBALS['uams_test_meta'] = $map;
    }

    /**
     * The term meta, per term ID and key.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function term_meta(): array
    {
        /** @var array<int, array<string, mixed>> $value */
        $value = self::array_at('uams_test_term_meta');

        return $value;
    }

    /** @param array<int, array<string, mixed>> $value */
    public static function set_term_meta(array $value): void
    {
        $GLOBALS['uams_test_term_meta'] = $value;
    }

    /** @param array<string, mixed> $item */
    public static function put_term_meta_row(int $id, array $item): void
    {
        $map = self::array_at('uams_test_term_meta');
        $map[$id] = $item;
        $GLOBALS['uams_test_term_meta'] = $map;
    }

    /**
     * The ACF field definitions, per selector.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function acf_fields(): array
    {
        /** @var array<string, array<string, mixed>> $value */
        $value = self::array_at('uams_test_acf_fields');

        return $value;
    }

    /** @param array<string, array<string, mixed>> $value */
    public static function set_acf_fields(array $value): void
    {
        $GLOBALS['uams_test_acf_fields'] = $value;
    }

    /** @param array<string, mixed> $item */
    public static function put_acf_field(string $name, array $item): void
    {
        $map = self::array_at('uams_test_acf_fields');
        $map[$name] = $item;
        $GLOBALS['uams_test_acf_fields'] = $map;
    }

    /**
     * The blog names the details stand-in answers, per site ID, or null when a test has not set any.
     *
     * @return array<int, string>|null
     */
    public static function blog_names(): ?array
    {
        /** @var array<int, string>|null $value */
        $value = self::array_or_null('uams_test_blog_names');

        return $value;
    }

    /** @param array<int, string> $value */
    public static function set_blog_names(array $value): void
    {
        $GLOBALS['uams_test_blog_names'] = $value;
    }

    public static function put_blog_name(int $id, string $item): void
    {
        $map = self::array_or_null('uams_test_blog_names') ?? [];
        $map[$id] = $item;
        $GLOBALS['uams_test_blog_names'] = $map;
    }

    /**
     * The posts the post stand-in answers, per post ID; null answers as no post.
     *
     * @return array<int, object|null>
     */
    public static function posts(): array
    {
        /** @var array<int, object|null> $value */
        $value = self::array_at('uams_test_posts');

        return $value;
    }

    /** @param array<int, object|null> $value */
    public static function set_posts(array $value): void
    {
        $GLOBALS['uams_test_posts'] = $value;
    }

    public static function put_post(int $id, ?object $item): void
    {
        $map = self::array_at('uams_test_posts');
        $map[$id] = $item;
        $GLOBALS['uams_test_posts'] = $map;
    }

    /**
     * The terms the term stand-in answers, per term ID.
     *
     * @return array<int, object>
     */
    public static function term_objects(): array
    {
        /** @var array<int, object> $value */
        $value = self::array_at('uams_test_term_objects');

        return $value;
    }

    /** @param array<int, object> $value */
    public static function set_term_objects(array $value): void
    {
        $GLOBALS['uams_test_term_objects'] = $value;
    }

    public static function put_term_object(int $id, object $item): void
    {
        $map = self::array_at('uams_test_term_objects');
        $map[$id] = $item;
        $GLOBALS['uams_test_term_objects'] = $map;
    }

    /**
     * The term names, or a failure, the term-list stand-in answers per taxonomy.
     *
     * @return array<string, list<string>|\WP_Error>
     */
    public static function terms(): array
    {
        /** @var array<string, list<string>|\WP_Error> $value */
        $value = self::array_at('uams_test_terms');

        return $value;
    }

    /** @param array<string, list<string>|\WP_Error> $value */
    public static function set_terms(array $value): void
    {
        $GLOBALS['uams_test_terms'] = $value;
    }

    /** @param list<string>|\WP_Error $item */
    public static function put_terms(string $name, array|\WP_Error $item): void
    {
        $map = self::array_at('uams_test_terms');
        $map[$name] = $item;
        $GLOBALS['uams_test_terms'] = $map;
    }

    /**
     * The post type objects, per post type name.
     *
     * @return array<string, object>
     */
    public static function types(): array
    {
        /** @var array<string, object> $value */
        $value = self::array_at('uams_test_types');

        return $value;
    }

    /** @param array<string, object> $value */
    public static function set_types(array $value): void
    {
        $GLOBALS['uams_test_types'] = $value;
    }

    public static function put_type(string $name, object $item): void
    {
        $map = self::array_at('uams_test_types');
        $map[$name] = $item;
        $GLOBALS['uams_test_types'] = $map;
    }

    /**
     * The taxonomy objects, per taxonomy name.
     *
     * @return array<string, object>
     */
    public static function taxonomies(): array
    {
        /** @var array<string, object> $value */
        $value = self::array_at('uams_test_taxonomies');

        return $value;
    }

    /** @param array<string, object> $value */
    public static function set_taxonomies(array $value): void
    {
        $GLOBALS['uams_test_taxonomies'] = $value;
    }

    public static function put_taxonomy(string $name, object $item): void
    {
        $map = self::array_at('uams_test_taxonomies');
        $map[$name] = $item;
        $GLOBALS['uams_test_taxonomies'] = $map;
    }

    /**
     * The post type labels the type-list stand-in answers.
     *
     * @return array<string, string>
     */
    public static function post_types(): array
    {
        /** @var array<string, string> $value */
        $value = self::array_at('uams_test_post_types');

        return $value;
    }

    /** @param array<string, string> $value */
    public static function set_post_types(array $value): void
    {
        $GLOBALS['uams_test_post_types'] = $value;
    }

    /**
     * The arguments the type-list stand-in was last called with.
     *
     * @return array<string, mixed>
     */
    public static function post_types_args(): array
    {
        /** @var array<string, mixed> $value */
        $value = self::array_at('uams_test_post_types_args');

        return $value;
    }

    /** @param array<string, mixed> $value */
    public static function set_post_types_args(array $value): void
    {
        $GLOBALS['uams_test_post_types_args'] = $value;
    }

    /**
     * The blog names the site stand-ins answer, per site ID.
     *
     * @return array<int, string>
     */
    public static function sites(): array
    {
        /** @var array<int, string> $value */
        $value = self::array_at('uams_test_sites');

        return $value;
    }

    /** @param array<int, string> $value */
    public static function set_sites(array $value): void
    {
        $GLOBALS['uams_test_sites'] = $value;
    }

    /**
     * The blog details the details stand-in answers, per site ID.
     *
     * @return array<int, object|false>
     */
    public static function site_details(): array
    {
        /** @var array<int, object|false> $value */
        $value = self::array_at('uams_test_site_details');

        return $value;
    }

    /** @param array<int, object|false> $value */
    public static function set_site_details(array $value): void
    {
        $GLOBALS['uams_test_site_details'] = $value;
    }

    /**
     * The next scheduled time, per cron hook.
     *
     * @return array<string, int>
     */
    public static function cron(): array
    {
        /** @var array<string, int> $value */
        $value = self::array_at('uams_test_cron');

        return $value;
    }

    /** @param array<string, int> $value */
    public static function set_cron(array $value): void
    {
        $GLOBALS['uams_test_cron'] = $value;
    }

    public static function put_cron(string $name, int $item): void
    {
        $map = self::array_at('uams_test_cron');
        $map[$name] = $item;
        $GLOBALS['uams_test_cron'] = $map;
    }

    /**
     * The field values the preview scripts answer, per post ID.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function preview_fields(): array
    {
        /** @var array<int, array<string, mixed>> $value */
        $value = self::array_at('uams_preview_fields');

        return $value;
    }

    /** @param array<int, array<string, mixed>> $value */
    public static function set_preview_fields(array $value): void
    {
        $GLOBALS['uams_preview_fields'] = $value;
    }

    /**
     * The titles the preview scripts answer, per post ID.
     *
     * @return array<int, string>
     */
    public static function preview_post_titles(): array
    {
        /** @var array<int, string> $value */
        $value = self::array_at('uams_preview_post_titles');

        return $value;
    }

    /** @param array<int, string> $value */
    public static function set_preview_post_titles(array $value): void
    {
        $GLOBALS['uams_preview_post_titles'] = $value;
    }

    /**
     * The post types the preview scripts answer, per post ID.
     *
     * @return array<int, string>
     */
    public static function preview_post_types(): array
    {
        /** @var array<int, string> $value */
        $value = self::array_at('uams_preview_post_types');

        return $value;
    }

    /** @param array<int, string> $value */
    public static function set_preview_post_types(array $value): void
    {
        $GLOBALS['uams_preview_post_types'] = $value;
    }

    /**
     * The thumbnail IDs the preview scripts answer, per post ID.
     *
     * @return array<int, int>
     */
    public static function preview_thumbnails(): array
    {
        /** @var array<int, int> $value */
        $value = self::array_at('uams_preview_thumbnails');

        return $value;
    }

    /** @param array<int, int> $value */
    public static function set_preview_thumbnails(array $value): void
    {
        $GLOBALS['uams_preview_thumbnails'] = $value;
    }

    /** The option named `$name`, or `$default` when the store has none; a stored null reads as none, as the `get_option()` stand-in answers it. */
    public static function option(string $name, mixed $default = false): mixed
    {
        return self::options()[$name] ?? $default;
    }

    /**
     * The callbacks registered for the filter named `$name`.
     *
     * @return list<callable>
     */
    public static function filters_for(string $name): array
    {
        return self::filters()[$name] ?? [];
    }

    public static function add_filter(string $name, callable $callback): void
    {
        $map = self::filters();
        $map[$name][] = $callback;
        $GLOBALS['uams_test_filters'] = $map;
    }

    public static function add_hook(string $name, callable $callback): void
    {
        $map = self::hooks();
        $map[$name][] = $callback;
        $GLOBALS['uams_test_hooks'] = $map;
    }

    /** @param list<mixed> $registration The callback, priority and argument count. */
    public static function add_hook_args(string $name, array $registration): void
    {
        $map = self::hook_args();
        $map[$name][] = $registration;
        $GLOBALS['uams_test_hook_args'] = $map;
    }

    public static function put_meta(int $id, string $key, mixed $value): void
    {
        $map = self::meta();
        $map[$id][$key] = $value;
        $GLOBALS['uams_test_meta'] = $map;
    }

    /** The post meta value for `$id` and `$key`, or `$default` when none is stored. */
    public static function meta_value(int $id, string $key, mixed $default = ''): mixed
    {
        return self::meta()[$id][$key] ?? $default;
    }

    public static function put_term_meta(int $id, string $key, mixed $value): void
    {
        $map = self::term_meta();
        $map[$id][$key] = $value;
        $GLOBALS['uams_test_term_meta'] = $map;
    }

    /** The term meta value for `$id` and `$key`, or `$default` when none is stored. */
    public static function term_meta_value(int $id, string $key, mixed $default = ''): mixed
    {
        return self::term_meta()[$id][$key] ?? $default;
    }

    /**
     * The ACF field definition for `$selector`, or false when none is stored.
     *
     * @return array<string, mixed>|false
     */
    public static function acf_field(string $selector): array|false
    {
        return self::acf_fields()[$selector] ?? false;
    }

    /** The next scheduled time for the cron hook, or false when none is scheduled. */
    public static function cron_next(string $hook): int|false
    {
        return self::cron()[$hook] ?? false;
    }

    /** Whether a test has set what the post stand-in answers for a post it holds no entry for; null is a valid answer (no post). */
    public static function has_posts_default(): bool
    {
        return array_key_exists('uams_test_posts_default', $GLOBALS);
    }

    public static function posts_default(): ?object
    {
        return self::object_at('uams_test_posts_default');
    }

    public static function set_posts_default(?object $value): void
    {
        $GLOBALS['uams_test_posts_default'] = $value;
    }

    /** Whether a test has set what the term stand-in answers in place of its own lookup. */
    public static function has_get_term(): bool
    {
        return array_key_exists('uams_test_get_term', $GLOBALS);
    }

    /** What the term stand-in answers: left `mixed` on purpose, because a test sets a term, a `WP_Error`, an array, a row object or a scalar to see how the code under test treats each. */
    public static function get_term(): mixed
    {
        return $GLOBALS['uams_test_get_term'] ?? null;
    }

    public static function set_get_term(mixed $value): void
    {
        $GLOBALS['uams_test_get_term'] = $value;
    }

    /**
     * The memory limit the stand-in reports, in bytes: one number, or a list answered in order.
     *
     * @return int|list<int>
     */
    public static function memory_bytes(): int|array
    {
        $value = $GLOBALS['uams_test_memory_bytes'] ?? -1;
        if (is_int($value)) {
            return $value;
        }

        if (! is_array($value)) {
            self::fail('uams_test_memory_bytes', 'an int or a list of ints', $value);
        }

        /** @var list<int> $list */
        $list = $value;

        return $list;
    }

    /** @param int|list<int> $value */
    public static function set_memory_bytes(int|array $value): void
    {
        $GLOBALS['uams_test_memory_bytes'] = $value;
    }

    /** The next number from a stored list, removed from it, or the single stored number. */
    public static function next_memory_bytes(): int
    {
        $limit = self::memory_bytes();
        if (! is_array($limit)) {
            return $limit;
        }

        $next = array_shift($limit);
        $GLOBALS['uams_test_memory_bytes'] = $limit;

        return $next ?? -1;
    }

    /**
     * The site IDs the site-list stand-in answers, one list or one per `public` and `all`, or null when a test has not set any.
     *
     * @return list<int>|array{public: list<int>, all: list<int>}|null
     */
    public static function site_ids(): ?array
    {
        /** @var list<int>|array{public: list<int>, all: list<int>}|null $value */
        $value = self::array_or_null('uams_test_site_ids');

        return $value;
    }

    /** @param list<int>|array{public: list<int>, all: list<int>} $value */
    public static function set_site_ids(array $value): void
    {
        $GLOBALS['uams_test_site_ids'] = $value;
    }

    /** Whether a test has put a database double in `$wpdb`. */
    public static function has_db(): bool
    {
        return isset($GLOBALS['wpdb']);
    }

    /**
     * The database double in `$wpdb`, checked to be an instance of `$class` so a test reads its recorded state with a known type.
     *
     * @template T of object
     *
     * @param  class-string<T>  $class
     * @return T
     */
    public static function db(string $class): object
    {
        return self::instance_at('wpdb', $class);
    }

    public static function set_db(object $value): void
    {
        $GLOBALS['wpdb'] = $value;
    }

    /**
     * The query double in `$wp_query`, checked to be an instance of `$class`.
     *
     * @template T of object
     *
     * @param  class-string<T>  $class
     * @return T
     */
    public static function wp_query(string $class): object
    {
        return self::instance_at('wp_query', $class);
    }

    public static function set_wp_query(object $value): void
    {
        $GLOBALS['wp_query'] = $value;
    }

    /** Remove every key this class owns, as a test's teardown does. */
    public static function forget_all(): void
    {
        foreach (self::KEYS as $key) {
            unset($GLOBALS[$key]);
        }
    }

    /**
     * @param  array<mixed>  $default
     * @return array<mixed>
     */
    private static function array_at(string $key, array $default = []): array
    {
        $value = $GLOBALS[$key] ?? $default;
        if (! is_array($value)) {
            self::fail($key, 'an array', $value);
        }

        return $value;
    }

    /** @return array<mixed>|null */
    private static function array_or_null(string $key): ?array
    {
        $value = $GLOBALS[$key] ?? null;
        if ($value !== null && ! is_array($value)) {
            self::fail($key, 'an array or null', $value);
        }

        return $value;
    }

    private static function bool_at(string $key, bool $default): bool
    {
        $value = $GLOBALS[$key] ?? $default;
        if (! is_bool($value)) {
            self::fail($key, 'a bool', $value);
        }

        return $value;
    }

    private static function string_at(string $key, string $default): string
    {
        $value = $GLOBALS[$key] ?? $default;
        if (! is_string($value)) {
            self::fail($key, 'a string', $value);
        }

        return $value;
    }

    private static function nullable_string_at(string $key): ?string
    {
        $value = $GLOBALS[$key] ?? null;
        if ($value !== null && ! is_string($value)) {
            self::fail($key, 'a string or null', $value);
        }

        return $value;
    }

    private static function object_at(string $key): ?object
    {
        $value = $GLOBALS[$key] ?? null;
        if ($value !== null && ! is_object($value)) {
            self::fail($key, 'an object or null', $value);
        }

        return $value;
    }

    /**
     * @template T of object
     *
     * @param  class-string<T>  $class
     * @return T
     */
    private static function instance_at(string $key, string $class): object
    {
        $value = $GLOBALS[$key] ?? null;
        if (! $value instanceof $class) {
            self::fail($key, 'an instance of '.$class, $value);
        }

        return $value;
    }

    private static function fail(string $key, string $expected, mixed $value): never
    {
        throw new UnexpectedValueException(sprintf('Stand-in state "%s" holds %s, expected %s.', $key, get_debug_type($value), $expected));
    }
}
