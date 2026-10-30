<?php
namespace UAMS_Search;

final class Plugin {
    public static function activate(): void {
        try { DB::install(); add_filter('cron_schedules', [self::class, 'schedules']); self::schedule(); }
        catch (\Throwable $e) { wp_die(esc_html('UAMS Search setup failed: ' . $e->getMessage())); }
    }
    public static function deactivate(): void { wp_clear_scheduled_hook('uams_search_queue'); wp_clear_scheduled_hook('uams_search_retention'); }
    public static function schedule(): void {
        if (is_multisite() && !is_main_site()) { return; }
        if (!wp_next_scheduled('uams_search_queue')) { wp_schedule_event(time() + 300, 'uams_search_five_minutes', 'uams_search_queue'); }
        if (!wp_next_scheduled('uams_search_retention')) { wp_schedule_event(time() + 3600, 'daily', 'uams_search_retention'); }
    }
    public static function boot(): void {
        add_filter('cron_schedules', [self::class, 'schedules']);
        // Activation runs before cron_schedules in some activation paths.
        add_action('init', [self::class, 'schedule']);
        add_action('uams_search_queue', static function () { try { Queue::process(15, 15); } catch (\Throwable $e) { error_log('UAMS Search: ' . $e->getMessage()); } });
        add_action('uams_search_retention', static function () { if (DB::ready()) { Misses::purge(); } });
        add_action('save_post', static fn($id) => Queue::related((int)$id), 100);
        add_action('acf/save_post', static function ($id) { if (is_numeric($id)) { Queue::push((int)$id); } }, 30);
        add_action('before_delete_post', static fn($id) => Queue::related((int)$id));
        add_action('deleted_post', static fn($id) => Queue::push((int)$id, 'delete'));
        add_action('delete_attachment', static fn($id) => Queue::push((int)$id, 'delete'));
        add_action('add_attachment', static fn($id) => Queue::push((int)$id));
        add_action('edit_attachment', static fn($id) => Queue::push((int)$id));
        foreach (['added_post_meta','updated_post_meta','deleted_post_meta'] as $hook) {
            add_action($hook, static function ($meta_id, $id, $key) { if (!in_array($key, ['_edit_lock','_edit_last'], true)) { Queue::push((int)$id); } }, 10, 3);
        }
        add_action('set_object_terms', static fn($id) => Queue::push((int)$id));
        add_action('added_term_relationship', static fn($id) => Queue::push((int)$id));
        add_action('deleted_term_relationships', static fn($id) => Queue::push((int)$id));
        add_action('edited_term', [Queue::class, 'term'], 10, 3);
        add_action('delete_term', static function ($id, $tt, $tax, $term, $objects) { foreach ($objects as $object) { Queue::push((int)$object); } }, 10, 5);
        Integration::hooks(); Admin::hooks();
        if (defined('WP_CLI') && WP_CLI) { \WP_CLI::add_command('uams-search', CLI::class); }
    }
    public static function schedules(array $s): array { $s['uams_search_five_minutes'] = ['interval' => 300, 'display' => 'Every five minutes']; return $s; }
}
