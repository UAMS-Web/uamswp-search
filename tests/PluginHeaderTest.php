<?php

namespace {

}

namespace UAMS_Search\Tests {
    use PHPUnit\Framework\TestCase;

    final class PluginHeaderTest extends TestCase
    {
        private function main_file(): string
        {
            return (string) file_get_contents(__DIR__.'/../uams-search.php');
        }

        public function test_the_version_header_is_a_three_segment_release_number_found_as_core_finds_it(): void
        {
            // The pattern `get_file_data()` applies to each header, run over the first 8 KB it reads
            $head = substr($this->main_file(), 0, 8192);
            self::assertSame(1, preg_match('/^[ \t\/*#@]*Version:(.*)$/mi', $head, $found));
            self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', trim($found[1]));
        }

        public function test_the_version_constant_is_read_from_the_header_and_never_written_as_a_literal(): void
        {
            $source = $this->main_file();
            self::assertStringContainsString("define('UAMS_SEARCH_VERSION', get_file_data(__FILE__, ['Version' => 'Version'])['Version']);", $source);
            self::assertSame(0, preg_match("/define\(\s*'UAMS_SEARCH_VERSION'\s*,\s*'[^']*'/", $source));
            self::assertSame(1, preg_match_all('/^[ \t\/*#@]*Version:/mi', $source));
        }

        public function test_the_unit_bootstrap_holds_the_version_the_header_states(): void
        {
            preg_match('/^[ \t\/*#@]*Version:(.*)$/mi', substr($this->main_file(), 0, 8192), $found);
            self::assertSame(trim($found[1]), UAMS_SEARCH_VERSION);
        }

        public function test_the_plugin_header_and_readme_name_the_plugin_uamswp_search(): void
        {
            $header = (string) file_get_contents(__DIR__.'/../uams-search.php');
            self::assertMatchesRegularExpression('/^ \* Plugin Name: UAMSWP Search$/m', $header);
            self::assertStringStartsWith('=== UAMSWP Search ===', (string) file_get_contents(__DIR__.'/../readme.txt'));
            self::assertStringStartsWith('# UAMSWP Search', (string) file_get_contents(__DIR__.'/../README.md'));
        }

        public function test_the_header_credits_uams_web_services_and_the_readme_lists_developers(): void
        {
            $header = (string) file_get_contents(__DIR__.'/../uams-search.php');
            self::assertMatchesRegularExpression('/^ \* Author: UAMS Web Services$/m', $header);
            self::assertMatchesRegularExpression('#^ \* Author URI: https://communications.uams.edu/web/$#m', $header);
            $readme = (string) file_get_contents(__DIR__.'/../README.md');
            self::assertStringContainsString('## Credits

Developed and maintained by UAMS Web Services.

Developers:

- Josh Daugherty
- Brent Passmore
', $readme);
        }

        public function test_exception_messages_send_the_reader_to_the_settings_by_their_current_name(): void
        {
            foreach (['includes/CLI.php', 'includes/Indexer.php'] as $file) {
                $source = (string) file_get_contents(__DIR__.'/../'.$file);
                self::assertStringContainsString('UAMSWP Search settings', $source, $file);
                self::assertStringNotContainsString('uams search settings', $source, $file);
            }
        }
    }
}
