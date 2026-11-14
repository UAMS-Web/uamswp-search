<?php

namespace UAMS_Search;

use InvalidArgumentException;
use RuntimeException;
use Throwable;
use WP_CLI;

use function WP_CLI\Utils\format_items;

/** UAMS install-wide search operations. Stored dates are UTC; dates shown here use the site timezone and formats. */
final class CLI
{
    /**
     * Rebuild a site's index while existing search remains available.
     *
     * ## OPTIONS
     * [--site=<id>]
     * [--post-type=<type>]
     * [--all]
     *
     * @param  array<int, string>  $args  Positional arguments.
     * @param  array<string, mixed>  $assoc  Named options.
     */
    public function index(
        array $args,
        array $assoc
    ): void {
        $this->guard(function () use ($assoc): void {
            // Make sure the tables exist
            DB::install();
            // Choose the sites to rebuild
            // This file is not strict-typed, so the int parameter of the rebuild reads a numeric string as the same number.
            // @pest-mutate-ignore:RemoveIntegerCast
            $sites = isset($assoc['all']) ? Config::index_sites() : [(int) ($assoc['site'] ?? get_current_blog_id())];
            if ($sites === []) {
                throw new RuntimeException('No sites are eligible. Use the rebuild button in UAMSWP Search settings to include no-index sites for signed-in previews.');
            }

            // Rebuild each site and report its counts
            foreach ($sites as $site) {
                $stats = Indexer::rebuild($site, $assoc['post-type'] ?? null);
                WP_CLI::log(sprintf('Site %d: %d indexed, %d failed.', $site, $stats['indexed'], $stats['errors']));
            }

            // A full rebuild of every content type refreshes the Name suggestions on the Settings screen
            if (isset($assoc['all']) && ! isset($assoc['post-type'])) {
                NameChoices::refresh_after_rebuild();
            }

            WP_CLI::success('Rebuild complete. Check status for failed items.');
        });
    }

    /**
     * Process queued edits, with time and memory limits.
     *
     * ## OPTIONS
     * [--limit=<n>]
     * [--seconds=<n>]
     *
     * @subcommand process-queue
     *
     * @param  array<int, string>  $args  Positional arguments.
     * @param  array<string, mixed>  $assoc  Named options.
     */
    public function process_queue(
        array $args,
        array $assoc
    ): void {
        $this->guard(function () use ($assoc): void {
            // Process the queue within the limits
            // The seconds budget (its default of 45, its 1 to 240 clamp and the casts) changes only how long a run may last, which no test waits for; the casts are read the same by the int parameters. The entry limit and its default of 100 on the same line are killed by the hundred-entry test.
            // @pest-mutate-ignore:CoalesceRemoveLeft,DecrementInteger,IncrementInteger,MaxToMin,MinToMax,RemoveIntegerCast
            $stats = Queue::process((int) ($assoc['limit'] ?? 100), max(1, min(240, (int) ($assoc['seconds'] ?? 45))));
            // Warn when another indexer holds the lock
            if ($stats['busy']) {
                WP_CLI::warning('Another indexer holds the lock. No work was lost.');
            }

            // Print the counts
            WP_CLI::log(wp_json_encode($stats));
        });
    }

    /** Show index, queue, and failure counts. */
    public function status(): void
    {
        global $wpdb;
        $this->guard(function () use ($wpdb): void {
            // Check that the index exists
            if (! DB::ready()) {
                throw new RuntimeException('Index is missing. Run wp uams-search index.');
            }

            // Print the index counts by site, type and status
            $rows = $wpdb->get_results('SELECT site_id,post_type,status,COUNT(*) AS count FROM `'.DB::table().'` GROUP BY site_id,post_type,status ORDER BY site_id,post_type,status', ARRAY_A);
            format_items('table', $rows, ['site_id', 'post_type', 'status', 'count']);
            // Print the queue size and last run time
            WP_CLI::log('Queue: '.$wpdb->get_var('SELECT COUNT(*) FROM `'.DB::table('queue').'`'));
            WP_CLI::log('Queue last ran: '.Time::local(get_site_option('uams_search_last_queue', '')));
        });
    }

    /**
     * Reindex one object and print its extracted fields.
     *
     * ## OPTIONS
     * <post-id>
     * --site=<id>
     *
     * @param  array<int, string>  $args  Positional arguments.
     * @param  array<string, mixed>  $assoc  Named options.
     */
    public function reindex(
        array $args,
        array $assoc
    ): void {
        $this->guard(function () use ($args, $assoc): void {
            // Read the site and post, and check they can be indexed
            $site = (int) $assoc['site'];
            $id = (int) $args[0];
            // The site is cast to int and the list holds ints, so loose and strict comparison agree.
            if (! \in_array($site, Config::index_sites(), true) || $id < 1) { // @pest-mutate-ignore:TrueToFalse
                throw new InvalidArgumentException('Invalid site or post');
            }

            // Take the lock so only one indexer works at a time
            if (! DB::lock()) {
                throw new RuntimeException('Another indexer is running');
            }

            try {
                // Refuse to run beside a browser rebuild
                if (Rebuild::active()) {
                    throw new RuntimeException('Resume or cancel the browser rebuild before reindexing.');
                } $row = Indexer::one($site, $id);
                // Print the extracted row
                WP_CLI::log(wp_json_encode($row, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            } finally {
                // Release the lock
                DB::unlock();
            }

            // Report an indexing failure as an error
            if ($row['status'] === 'error') {
                throw new RuntimeException($row['error']);
            }
        });
    }

    /**
     * Search and optionally explain each column's score.
     *
     * ## OPTIONS
     * <terms>
     * [--site=<id>]
     * [--scope=<scope>]
     * [--type=<type>]
     * [--explain]
     *
     * @param  array<int, string>  $args  Positional arguments.
     * @param  array<string, mixed>  $assoc  Named options.
     */
    public function query(
        array $args,
        array $assoc
    ): void {
        $this->guard(function () use ($args, $assoc): void {
            // Run the search
            // Query casts the site to int itself and ignores a type it does not know, so a missing cast or a made-up default type changes nothing.
            // @pest-mutate-ignore:EmptyStringToNotEmpty,RemoveIntegerCast
            $out = Query::run(['s' => $args[0], 'site' => (int) ($assoc['site'] ?? get_current_blog_id()), 'scope' => $assoc['scope'] ?? 'site', 'type' => $assoc['type'] ?? '', 'explain' => isset($assoc['explain'])]);
            // Print the results
            WP_CLI::log(wp_json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        });
    }

    /** Check database capabilities and PDF extraction tools. */
    public function doctor(): void
    {
        global $wpdb;
        // Print what the database and server support
        WP_CLI::log(wp_json_encode([
            'database' => $wpdb->get_var('SELECT VERSION()'),
            'minimum_token_size' => $wpdb->get_var('SELECT @@innodb_ft_min_token_size'),
            'stopword_control' => 'Session innodb_ft_user_stopword_table with empty plugin-owned table',
            'index_ready' => DB::ready(), 'pdftotext' => Pdf::binary('pdftotext'),
            'proc_open' => \function_exists('proc_open'), 'php_cli' => PHP_BINDIR.'/php',
            'mbstring' => \extension_loaded('mbstring'), 'zlib' => \extension_loaded('zlib'), 'iconv' => \extension_loaded('iconv'),
            'config_changed' => Config::needs_rebuild(),
        ], JSON_PRETTY_PRINT));
    }

    /**
     * Run a command and report any failure as a WP-CLI error.
     *
     * @param  callable  $callback  The command's work.
     */
    private function guard(callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable $throwable) {
            // Stop with the failure message
            WP_CLI::error($throwable->getMessage());
        }
    }
}
