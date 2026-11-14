<?php

declare(strict_types=1);

namespace UAMS_Search\Tests\Integration;

use RuntimeException;
use UAMS_Search\CLI;
use UAMS_Search\DB;
use UAMS_Search\Plugin;
use UAMS_Search\Queue;
use UAMS_Search\Tests\Support\IntegrationTestCase;
use WP_CLI;

// The recording WP_CLI stand-in lives with the command tests
require_once __DIR__.'/CLITest.php';

/**
 * The plugin's own wiring: activation, the queue job, the plugin header file and the WP-CLI command registration,
 * run against real WordPress. The unit suite pins the scheduling rules against stand-ins.
 */
final class PluginWiringTest extends IntegrationTestCase
{
    private string $log = '';

    private string|false $oldLog = '';

    public function set_up(): void
    {
        parent::set_up();
        $this->log = (string) tempnam(sys_get_temp_dir(), 'uams-search-log');
        $this->oldLog = ini_set('error_log', $this->log);
    }

    public function tear_down(): void
    {
        ini_set('error_log', (string) $this->oldLog);
        if (is_file($this->log)) {
            unlink($this->log);
        }

        wp_clear_scheduled_hook('uams_search_queue');
        wp_clear_scheduled_hook('uams_search_retention');
        parent::tear_down();
    }

    public function test_activation_schedules_the_queue_and_retention_jobs(): void
    {
        wp_clear_scheduled_hook('uams_search_queue');
        wp_clear_scheduled_hook('uams_search_retention');
        remove_filter('cron_schedules', [Plugin::class, 'schedules']);

        Plugin::activate();

        $this->assertNotFalse(wp_next_scheduled('uams_search_queue'));
        $this->assertNotFalse(wp_next_scheduled('uams_search_retention'));
        $this->assertNotFalse(has_filter('cron_schedules', [Plugin::class, 'schedules']), 'The five-minute schedule is registered before the jobs');
        $this->assertSame('uams_search_five_minutes', wp_get_schedule('uams_search_queue'));
    }

    public function test_activation_creates_a_missing_table(): void
    {
        global $wpdb;
        $table = DB::table('queue');
        $wpdb->query("DROP TABLE `$table`");
        try {
            Plugin::activate();
            $this->assertSame($table, $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))));
        } finally {
            DB::install();
        }
    }

    public function test_activation_stops_with_the_reason_when_setup_fails(): void
    {
        $fail = static function (): never {
            throw new RuntimeException('boom');
        };
        add_filter('query', $fail);
        try {
            Plugin::activate();
            $this->fail('A setup failure must stop activation');
        } catch (\WPDieException $wpDieException) {
            $this->assertSame('UAMS Search setup failed: boom', $wpDieException->getMessage());
        } finally {
            remove_filter('query', $fail);
        }
    }

    public function test_the_queue_job_runs_the_queue_and_logs_a_failure_without_stopping(): void
    {
        do_action('uams_search_queue');
        $this->assertStringNotContainsString('UAMS Search:', (string) file_get_contents($this->log), 'A healthy run logs nothing');

        $fail = static function (): never {
            throw new RuntimeException('queue down');
        };
        add_filter('query', $fail);
        try {
            do_action('uams_search_queue');
        } finally {
            remove_filter('query', $fail);
        }

        $this->assertStringContainsString('UAMS Search: queue down', (string) file_get_contents($this->log));
    }

    public function test_the_queue_job_reads_fifteen_entries_a_run(): void
    {
        global $wpdb;
        $table = DB::table('queue');
        $ids = range(900001, 900016);
        foreach ($ids as $id) {
            Queue::push($id);
        }

        try {
            // Booted once already, on plugins_loaded; booting again would run the job twice
            do_action('uams_search_queue');
            $this->assertSame(1, (int) $wpdb->get_var("SELECT COUNT(*) FROM `$table` WHERE object_id BETWEEN 900001 AND 900016"), 'One entry of sixteen is left for the next run');
        } finally {
            $wpdb->query("DELETE FROM `$table` WHERE object_id BETWEEN 900001 AND 900016");
        }
    }

    public function test_the_schedule_is_also_checked_on_every_request(): void
    {
        remove_action('init', [Plugin::class, 'schedule']);
        Plugin::boot();

        $this->assertNotFalse(has_action('init', [Plugin::class, 'schedule']), 'Activation can run before the schedule exists');
    }

    public function test_the_plugin_file_registers_its_hooks_and_defines_its_constants(): void
    {
        $file = dirname(__DIR__, 2).'/uams-search.php';
        $basename = plugin_basename($file);
        remove_action('activate_'.$basename, [Plugin::class, 'activate']);
        remove_action('deactivate_'.$basename, [Plugin::class, 'deactivate']);
        remove_action('plugins_loaded', [Plugin::class, 'boot'], 20);

        // The constants are already defined by the bootstrap; loading the file again warns once for each
        $warned = [];
        set_error_handler(static function (int $no, string $message) use (&$warned): bool {
            $warned[] = $message;

            return true;
        }, E_WARNING);
        try {
            include $file;
        } finally {
            restore_error_handler();
        }

        $this->assertNotFalse(has_action('activate_'.$basename, [Plugin::class, 'activate']));
        $this->assertNotFalse(has_action('deactivate_'.$basename, [Plugin::class, 'deactivate']));
        $this->assertSame(20, has_action('plugins_loaded', [Plugin::class, 'boot']));
        $this->assertCount(3, $warned);
        foreach (['UAMS_SEARCH_FILE', 'UAMS_SEARCH_VERSION', 'UAMS_SEARCH_SCHEMA_VERSION'] as $name) {
            $this->assertStringContainsString($name, implode("\n", $warned));
        }

        $this->assertSame($file, UAMS_SEARCH_FILE);
        $this->assertSame(get_file_data($file, ['Version' => 'Version'])['Version'], UAMS_SEARCH_VERSION, 'The constant follows the version in the plugin header');
    }

    public function test_each_content_hook_keeps_its_priority_and_the_arguments_it_accepts(): void
    {
        global $wp_filter;
        $expected = [
            'save_post' => [[100, 1]], 'acf/save_post' => [[30, 1]],
            'added_post_meta' => [[10, 3]], 'updated_post_meta' => [[10, 3]], 'deleted_post_meta' => [[10, 3]],
            'edited_term' => [[10, 3]], 'delete_term' => [[10, 5]],
        ];
        foreach ($expected as $hook => $wanted) {
            $mine = [];
            foreach ($wp_filter[$hook]->callbacks as $priority => $callbacks) {
                foreach ($callbacks as $callback) {
                    $function = $callback['function'];
                    $from = $function instanceof \Closure ? (string) (new \ReflectionFunction($function))->getFileName() : '';
                    if (str_ends_with($from, 'Plugin.php') || $function === [Queue::class, 'term']) {
                        $mine[] = [$priority, $callback['accepted_args']];
                    }
                }
            }

            $this->assertSame($wanted, array_values(array_unique($mine, SORT_REGULAR)), $hook);
        }
    }

    /**
     * Last in the file, because WP_CLI is defined for the rest of the process once set.
     */
    public function test_the_wp_cli_command_is_registered_when_wp_cli_is_running(): void
    {
        if (\defined('WP_CLI') && ! WP_CLI) {
            $this->markTestSkipped('WP_CLI is defined as false in this process.');
        }

        if (! \defined('WP_CLI')) {
            \define('WP_CLI', true);
        }

        WP_CLI::$commands = [];
        Plugin::boot();

        $this->assertSame(['uams-search' => CLI::class], WP_CLI::$commands);
    }
}
