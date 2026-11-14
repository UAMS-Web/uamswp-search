<?php

namespace {

    if (! function_exists('esc_html')) {
        function esc_html($s)
        {
            return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
    }

    // Stand-in for the multisite lookup: counts calls so per-site caching can be asserted.
    function get_blog_details($id)
    {
        $GLOBALS['uams_test_blog_lookups'][] = $id;
        $sites = $GLOBALS['uams_test_sites'] ?? [];

        return isset($sites[$id]) ? (object) ['blogname' => $sites[$id]] : false;
    }
}

namespace UAMS_Search\Tests {
    use PHPUnit\Framework\TestCase;
    use ReflectionMethod;
    use UAMS_Search\Admin;
    use UAMS_Search\SiteNames;

    final class SiteNamesTest extends TestCase
    {
        protected function setUp(): void
        {
            SiteNames::reset();
            // The lookups below are the multisite ones
            $GLOBALS['uams_test_multisite'] = true;
            $GLOBALS['uams_test_blog_lookups'] = [];
            $GLOBALS['uams_test_sites'] = [1 => 'Main Site', 4 => 'Clinics <b>&</b> "Care"'];
        }

        protected function tearDown(): void
        {
            unset($GLOBALS['uams_test_multisite']);
        }

        public function test_known_site_and_missing_site(): void
        {
            self::assertSame('Main Site', SiteNames::name(1));
            self::assertSame('(site not found)', SiteNames::name(99));
        }

        public function test_only_site_names_reads_get_blog_details(): void
        {
            // get_blog_details() returns false for a deleted site; SiteNames is the one reader that checks.
            $readers = [];
            foreach (glob(__DIR__.'/../includes/*.php') as $file) {
                if (str_contains((string) file_get_contents($file), 'get_blog_details(')) {
                    $readers[] = basename($file);
                }
            }

            self::assertSame(['SiteNames.php'], $readers, 'Admin and Rebuild take site titles from SiteNames::name().');
        }

        public function test_rows_get_name_then_id_and_keep_their_order(): void
        {
            $rows = SiteNames::prefix([['1', 'post', 'indexed', '5'], ['4', 'page', 'error', '2'], ['99', 'post', 'indexed', '1']]);
            self::assertSame([['Main Site', 1, 'post', 'indexed', '5'], [$GLOBALS['uams_test_sites'][4], 4, 'page', 'error', '2'], ['(site not found)', 99, 'post', 'indexed', '1']], $rows);
        }

        public function test_a_single_site_reads_its_title_from_the_option_and_never_calls_get_blog_details(): void
        {
            $GLOBALS['uams_test_multisite'] = false;
            try {
                $GLOBALS['uams_test_options'] = ['blogname' => 'Only Site'];
                self::assertSame('Only Site', SiteNames::name(1));
                self::assertSame('(site not found)', SiteNames::name(2), 'A single site has no other site');
                SiteNames::reset();
                $GLOBALS['uams_test_options'] = ['blogname' => ''];
                self::assertSame('(site not found)', SiteNames::name(1), 'An empty title reads as missing');
                self::assertSame([], $GLOBALS['uams_test_blog_lookups']);
            } finally {
                $GLOBALS['uams_test_options'] = [];
            }
        }

        public function test_each_site_is_looked_up_once(): void
        {
            SiteNames::prefix([['1', 'a'], ['1', 'b'], ['4', 'c'], ['1', 'd'], ['99', 'e'], ['99', 'f']]);
            self::assertSame([1, 4, 99], $GLOBALS['uams_test_blog_lookups']);
        }

        public function test_site_names_are_escaped_in_the_table(): void
        {
            $table = new ReflectionMethod(Admin::class, 'table');
            ob_start();
            $table->invoke(null, ['Site Name', 'Site ID', 'Count'], SiteNames::prefix([['4', '2']]));
            $html = ob_get_clean();
            self::assertStringContainsString('<td>Clinics &lt;b&gt;&amp;&lt;/b&gt; &quot;Care&quot;</td><td>4</td><td>2</td>', $html);
            self::assertStringNotContainsString('<b>', $html);
        }
    }
}
