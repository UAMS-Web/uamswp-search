<?php

namespace UAMS_Search;

use Throwable;
use WP_CLI;

/** Starts the plugin: installs it, schedules its jobs and connects its hooks. */
final class Plugin
{
    /**
     * Create the tables and schedule the background jobs on activation.
     */
    public static function activate(): void
    {
        try {
            // Create or update the tables
            DB::install();
            // Register the five-minute schedule and schedule the jobs
            add_filter('cron_schedules', [self::class, 'schedules']);
            self::schedule();
        } catch (Throwable $throwable) {
            // Stop activation and show why setup failed
            wp_die(esc_html('UAMS Search setup failed: '.$throwable->getMessage()));
        }
    }

    /**
     * Remove the scheduled jobs on deactivation.
     */
    public static function deactivate(): void
    {
        // Remove the queue and retention jobs
        wp_clear_scheduled_hook('uams_search_queue');
        wp_clear_scheduled_hook('uams_search_retention');
    }

    /**
     * Schedule the queue and retention jobs on the main site only.
     */
    public static function schedule(): void
    {
        // Leave the network's jobs to the main site
        if (is_multisite() && ! is_main_site()) {
            return;
        }

        // Schedule the queue job if it is missing
        if (! wp_next_scheduled('uams_search_queue')) {
            wp_schedule_event(time() + 300, 'uams_search_five_minutes', 'uams_search_queue');
        }

        // Schedule the retention job if it is missing
        if (! wp_next_scheduled('uams_search_retention')) {
            wp_schedule_event(time() + 3600, 'daily', 'uams_search_retention');
        }
    }

    /**
     * Connect the plugin to WordPress: the jobs, the hooks that queue changed
     * content, the search integration, the settings screens and WP-CLI.
     */
    public static function boot(): void
    {
        // Register the five-minute schedule
        add_filter('cron_schedules', [self::class, 'schedules']);
        // Activation runs before cron_schedules in some activation paths.
        add_action('init', [self::class, 'schedule']);
        // Process the queue on schedule, logging any failure
        add_action('uams_search_queue', static function (): void {
            try {
                Queue::process(15, 15);
            } catch (Throwable $throwable) {
                error_log('UAMS Search: '.$throwable->getMessage());
            }
        });
        // Remove old recorded searches on schedule
        add_action('uams_search_retention', static function (): void {
            if (DB::ready()) {
                Misses::purge();
            }
        });
        // Queue a post, and its attachments, when it is saved or deleted
        add_action('save_post', static function (mixed $id): void {
            Queue::related((int) $id);
        }, 100);
        // Queue the post when ACF saves its fields
        add_action('acf/save_post', static function (mixed $id): void {
            if (is_numeric($id)) {
                Queue::push((int) $id);
            }
        }, 30);
        add_action('before_delete_post', static function (mixed $id): void {
            Queue::related((int) $id);
        });
        // Queue the removal of deleted posts and attachments
        add_action('deleted_post', static function (mixed $id): void {
            Queue::push((int) $id, 'delete');
        });
        add_action('delete_attachment', static function (mixed $id): void {
            Queue::push((int) $id, 'delete');
        });
        // Queue attachments when they are added or edited
        add_action('add_attachment', static function (mixed $id): void {
            Queue::push((int) $id);
        });
        add_action('edit_attachment', static function (mixed $id): void {
            Queue::push((int) $id);
        });
        // Queue a post when its meta changes, except the edit lock
        foreach (['added_post_meta', 'updated_post_meta', 'deleted_post_meta'] as $hook) {
            add_action($hook, static function (mixed $meta_id, mixed $id, mixed $key): void {
                if (! \in_array($key, ['_edit_lock', '_edit_last'], true)) {
                    Queue::push((int) $id);
                }
            }, 10, 3);
        }

        // Queue a post when its terms change
        add_action('set_object_terms', static function (mixed $id): void {
            Queue::push((int) $id);
        });
        add_action('added_term_relationship', static function (mixed $id): void {
            Queue::push((int) $id);
        });
        add_action('deleted_term_relationships', static function (mixed $id): void {
            Queue::push((int) $id);
        });
        // Queue the posts that use a term when it is renamed or deleted
        add_action('edited_term', [Queue::class, 'term'], 10, 3);
        add_action('delete_term', static function (mixed $id, mixed $tt, mixed $tax, mixed $term, mixed $objects): void {
            foreach ((array) $objects as $object) {
                Queue::push((int) $object);
            }
        }, 10, 5);
        // Connect the search integration and the settings screens
        Integration::hooks();
        Admin::hooks();
        // Register the WP-CLI command
        if (\defined('WP_CLI') && WP_CLI) {
            WP_CLI::add_command('uams-search', CLI::class);
        }
    }

    /**
     * Add the five-minute interval to WordPress's cron schedules.
     *
     * @param  array<string, array<string, mixed>>  $s  Existing schedules.
     * @return array<string, array<string, mixed>> The schedules plus
     *                                             `uams_search_five_minutes`.
     */
    public static function schedules(array $s): array
    {
        // Add the interval
        $s['uams_search_five_minutes'] = ['interval' => 300, 'display' => 'Every five minutes'];

        return $s;
    }
}
