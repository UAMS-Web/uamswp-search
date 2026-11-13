<?php

use PHPUnit\Framework\TestCase;

final class PluginHeaderTest extends TestCase
{
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
