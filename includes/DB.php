<?php
namespace UAMS_Search;

final class DB {
    private static ?bool $table_ready = null;
    public static function table(string $suffix = 'index'): string {
        global $wpdb;
        if (!in_array($suffix, ['index', 'queue', 'words', 'word_docs', 'misses', 'stopwords', 'stage'], true)) { throw new \InvalidArgumentException('Unknown table'); }
        return $wpdb->base_prefix . 'uams_search_' . $suffix;
    }
    public static function exec(string $sql): int {
        global $wpdb;
        $result = $wpdb->query($sql);
        if ($result === false) { throw new \RuntimeException($wpdb->last_error ?: 'Database operation failed'); }
        return $result;
    }
    public static function ready(): bool {
        global $wpdb;
        if (get_site_option('uams_search_version') !== UAMS_SEARCH_SCHEMA_VERSION) { return false; }
        if (self::$table_ready !== null) { return self::$table_ready; }
        $t = self::table();
        return self::$table_ready = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($t))) === $t;
    }
    public static function install(): void {
        global $wpdb;
        if (!extension_loaded('mbstring') || !extension_loaded('zlib') || !extension_loaded('iconv')) { throw new \RuntimeException('UAMS Search requires the mbstring, zlib and iconv PHP extensions.'); }
        if ((int)$wpdb->get_var('SELECT @@innodb_ft_min_token_size') !== 3) {
            throw new \RuntimeException('UAMS Search requires innodb_ft_min_token_size=3. Run wp uams-search doctor.');
        }
        $collation = $wpdb->get_charset_collate();
        $empty = self::table('stopwords');
        self::exec("CREATE TABLE IF NOT EXISTS `$empty` (`value` VARCHAR(30) NOT NULL) ENGINE=InnoDB $collation");
        $old = $wpdb->get_var('SELECT @@SESSION.innodb_ft_user_stopword_table');
        // MariaDB exposes enable_stopword globally only. An empty session word list needs no global grants.
        self::exec($wpdb->prepare('SET SESSION innodb_ft_user_stopword_table = %s', $wpdb->dbname . '/' . $empty));
        try {
            $t = self::table();
            self::exec("CREATE TABLE IF NOT EXISTS `$t` (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                site_id BIGINT UNSIGNED NOT NULL, object_id BIGINT UNSIGNED NOT NULL,
                object_type VARCHAR(20) NOT NULL DEFAULT 'post', post_type VARCHAR(40) NOT NULL DEFAULT 'page',
                title TEXT NOT NULL, title_stem TEXT NOT NULL,
                content LONGTEXT NOT NULL, content_stem LONGTEXT NOT NULL,
                fields LONGTEXT NOT NULL, fields_stem LONGTEXT NOT NULL,
                terms LONGTEXT NOT NULL, terms_stem LONGTEXT NOT NULL,
                pdf LONGTEXT NOT NULL, pdf_stem LONGTEXT NOT NULL,
                short_tokens LONGTEXT NOT NULL, search_stem LONGTEXT NOT NULL, weighted_data LONGTEXT NOT NULL,
                url TEXT NOT NULL, published_at DATETIME NULL, modified_at DATETIME NULL,
                indexed_at DATETIME NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'ready', error TEXT NOT NULL,
                file_signature VARCHAR(64) NOT NULL DEFAULT '', file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (id), UNIQUE KEY object (site_id,object_id), KEY state (status,site_id,post_type),
                FULLTEXT KEY title_ft (title_stem), FULLTEXT KEY content_ft (content_stem),
                FULLTEXT KEY fields_ft (fields_stem), FULLTEXT KEY terms_ft (terms_stem),
                FULLTEXT KEY pdf_ft (pdf_stem), FULLTEXT KEY short_ft (short_tokens), FULLTEXT KEY search_ft (search_stem)
            ) ENGINE=InnoDB $collation");
            // Staging is durable but never searched. Copy includes the same indexes and stopword metadata.
            self::exec('CREATE TABLE IF NOT EXISTS `' . self::table('stage') . "` LIKE `$t`");
            self::short_token_storage();
        } finally {
            self::exec($old === null ? 'SET SESSION innodb_ft_user_stopword_table = NULL' : $wpdb->prepare('SET SESSION innodb_ft_user_stopword_table = %s', $old));
        }
        $q = self::table('queue');
        self::exec("CREATE TABLE IF NOT EXISTS `$q` (
            site_id BIGINT UNSIGNED NOT NULL, object_id BIGINT UNSIGNED NOT NULL,
            action VARCHAR(20) NOT NULL DEFAULT 'upsert', queued_at DATETIME NOT NULL,
            available_at DATETIME NOT NULL, attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
            revision BIGINT UNSIGNED NOT NULL DEFAULT 1,
            PRIMARY KEY (site_id,object_id), KEY pending (attempts,available_at)
        ) ENGINE=InnoDB $collation");
        $w = self::table('words');
        self::exec("CREATE TABLE IF NOT EXISTS `$w` (
            word VARCHAR(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL, phonetic VARCHAR(32) NOT NULL, word_length SMALLINT NOT NULL,
            frequency BIGINT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (word), KEY sounds (phonetic), KEY lengths (word_length,frequency)
        ) ENGINE=InnoDB $collation");
        $w = self::table('word_docs');
        self::exec("CREATE TABLE IF NOT EXISTS `$w` (
            site_id BIGINT UNSIGNED NOT NULL, object_id BIGINT UNSIGNED NOT NULL, word VARCHAR(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
            PRIMARY KEY (site_id,object_id,word), KEY word (word)
        ) ENGINE=InnoDB $collation");
        self::dictionary_collation();
        $m = self::table('misses');
        self::exec("CREATE TABLE IF NOT EXISTS `$m` (
            site_id BIGINT UNSIGNED NOT NULL, search_hash CHAR(64) NOT NULL, search_text VARCHAR(200) NOT NULL,
            day DATE NOT NULL, count BIGINT UNSIGNED NOT NULL DEFAULT 1,
            PRIMARY KEY (site_id,search_hash,day), KEY retention (day)
        ) ENGINE=InnoDB $collation");
        update_site_option('uams_search_version', UAMS_SEARCH_SCHEMA_VERSION);
        self::$table_ready = true;
    }
    private static function dictionary_collation(): void {
        global $wpdb;
        $change = [];
        foreach (['words', 'word_docs'] as $suffix) {
            $table = self::table($suffix);
            $column = $wpdb->get_row("SHOW FULL COLUMNS FROM `$table` WHERE Field='word'", ARRAY_A);
            if (!$column) { throw new \RuntimeException($wpdb->last_error ?: 'Missing dictionary word column'); }
            if ($column['Collation'] !== 'utf8mb4_bin') { $change[] = $table; }
        }
        if (!$change) { return; }
        if (!self::lock()) { throw new \RuntimeException('Another indexer is running. Retry schema setup after it finishes.'); }
        try {
            // PHP's token deduplication compares Unicode bytes. Use the same identity
            // for dictionary keys so ligatures such as first/ﬁrst cannot collide.
            foreach ($change as $table) { self::exec("ALTER TABLE `$table` MODIFY word VARCHAR(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL"); }
        } finally { self::unlock(); }
    }
    private static function short_token_storage(): void {
        global $wpdb;
        $change = [];
        foreach (['index', 'stage'] as $suffix) {
            $table = self::table($suffix);
            $column = $wpdb->get_row("SHOW FULL COLUMNS FROM `$table` WHERE Field='short_tokens'", ARRAY_A);
            if (!$column) { throw new \RuntimeException($wpdb->last_error ?: 'Missing short-token column'); }
            if (strtolower($column['Type']) !== 'longtext') { $change[] = $table; }
        }
        if (!$change) { return; }
        if (!self::lock()) { throw new \RuntimeException('Another indexer is running. Retry schema setup after it finishes.'); }
        try {
            // Called while the session's empty stopword table is selected, since
            // this ALTER can rebuild the table and its full-text indexes.
            foreach ($change as $table) { self::exec("ALTER TABLE `$table` MODIFY short_tokens LONGTEXT NOT NULL"); }
        } finally { self::unlock(); }
    }
    public static function lock(): bool {
        global $wpdb;
        return (int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)', 'uams-search-' . md5($wpdb->dbname . $wpdb->base_prefix))) === 1;
    }
    public static function unlock(): void {
        global $wpdb;
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', 'uams-search-' . md5($wpdb->dbname . $wpdb->base_prefix)));
    }
}
