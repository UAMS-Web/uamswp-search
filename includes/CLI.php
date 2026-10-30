<?php
namespace UAMS_Search;

/** UAMS install-wide search operations. All dates are UTC. */
final class CLI {
    /**
     * Rebuild a site's index while existing search remains available.
     *
     * ## OPTIONS
     * [--site=<id>]
     * [--post-type=<type>]
     * [--all]
     */
    public function index(array $args, array $assoc): void {
        $this->guard(function () use ($assoc) {
            DB::install();
            $sites = isset($assoc['all']) ? Config::public_sites() : [(int)($assoc['site'] ?? get_current_blog_id())];
            foreach ($sites as $site) {
                $stats = Indexer::rebuild($site, $assoc['post-type'] ?? null);
                \WP_CLI::log(sprintf('Site %d: %d indexed, %d failed.', $site, $stats['indexed'], $stats['errors']));
            }
            \WP_CLI::success('Rebuild complete. Check status for failed items.');
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
     */
    public function process_queue(array $args, array $assoc): void {
        $this->guard(function () use ($assoc) {
            $stats = Queue::process((int)($assoc['limit'] ?? 100), max(1, min(240, (int)($assoc['seconds'] ?? 45))));
            if ($stats['busy']) { \WP_CLI::warning('Another indexer holds the lock. No work was lost.'); }
            \WP_CLI::log(wp_json_encode($stats));
        });
    }
    /** Show index, queue, and failure counts. */
    public function status(array $args, array $assoc): void {
        global $wpdb;
        $this->guard(function () use ($wpdb) {
            if (!DB::ready()) { throw new \RuntimeException('Index is missing. Run wp uams-search index.'); }
            $rows = $wpdb->get_results('SELECT site_id,post_type,status,COUNT(*) AS count FROM `' . DB::table() . '` GROUP BY site_id,post_type,status ORDER BY site_id,post_type,status', ARRAY_A);
            \WP_CLI\Utils\format_items('table', $rows, ['site_id','post_type','status','count']);
            \WP_CLI::log('Queue: ' . $wpdb->get_var('SELECT COUNT(*) FROM `' . DB::table('queue') . '`'));
            \WP_CLI::log('Queue last ran (UTC): ' . get_site_option('uams_search_last_queue', 'Never'));
        });
    }
    /**
     * Reindex one object and print its extracted fields.
     *
     * ## OPTIONS
     * <post-id>
     * --site=<id>
     */
    public function reindex(array $args, array $assoc): void {
        $this->guard(function () use ($args, $assoc) {
            $site = (int)$assoc['site']; $id = (int)$args[0];
            if (!in_array($site, Config::public_sites(), true) || $id < 1) { throw new \InvalidArgumentException('Invalid site or post'); }
            if (!DB::lock()) { throw new \RuntimeException('Another indexer is running'); }
            try { $row = Indexer::one($site, $id); \WP_CLI::log(wp_json_encode($row, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); }
            finally { DB::unlock(); }
            if ($row['status'] === 'error') { throw new \RuntimeException($row['error']); }
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
     */
    public function query(array $args, array $assoc): void {
        $this->guard(function () use ($args, $assoc) {
            $out = Query::run(['s' => $args[0], 'site' => (int)($assoc['site'] ?? get_current_blog_id()), 'scope' => $assoc['scope'] ?? 'site', 'type' => $assoc['type'] ?? '', 'explain' => isset($assoc['explain'])]);
            \WP_CLI::log(wp_json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        });
    }
    /** Check database capabilities and PDF extraction tools. */
    public function doctor(array $args, array $assoc): void {
        global $wpdb;
        \WP_CLI::log(wp_json_encode([
            'database' => $wpdb->get_var('SELECT VERSION()'),
            'minimum_token_size' => $wpdb->get_var('SELECT @@innodb_ft_min_token_size'),
            'stopword_control' => 'Session innodb_ft_user_stopword_table with empty plugin-owned table',
            'index_ready' => DB::ready(), 'pdftotext' => Pdf::binary('pdftotext'),
            'proc_open' => function_exists('proc_open'), 'php_cli' => PHP_BINDIR . '/php',
            'mbstring' => extension_loaded('mbstring'), 'zlib' => extension_loaded('zlib'), 'iconv' => extension_loaded('iconv'),
            'config_changed' => Config::needs_rebuild(),
        ], JSON_PRETTY_PRINT));
    }
    private function guard(callable $callback): void {
        try { $callback(); } catch (\Throwable $e) { \WP_CLI::error($e->getMessage()); }
    }
}
