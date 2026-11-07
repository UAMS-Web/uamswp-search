<?php
use PHPUnit\Framework\TestCase;
use UAMS_Search\{Admin, SiteNames};

if (!function_exists('esc_html')) { function esc_html($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); } }
// Stand-in for the multisite lookup: counts calls so per-site caching can be asserted.
function get_blog_details($id) {
    $GLOBALS['uams_test_blog_lookups'][] = $id;
    $sites = $GLOBALS['uams_test_sites'] ?? [];
    return isset($sites[$id]) ? (object)['blogname' => $sites[$id]] : false;
}

final class SiteNamesTest extends TestCase {
    protected function setUp(): void {
        SiteNames::reset();
        $GLOBALS['uams_test_blog_lookups'] = [];
        $GLOBALS['uams_test_sites'] = [1 => 'Main Site', 4 => 'Clinics <b>&</b> "Care"'];
    }
    public function testKnownSiteAndMissingSite(): void {
        self::assertSame('Main Site', SiteNames::name(1));
        self::assertSame('(site not found)', SiteNames::name(99));
    }
    public function testRowsGetNameThenIdAndKeepTheirOrder(): void {
        $rows = SiteNames::prefix([['1', 'post', 'indexed', '5'], ['4', 'page', 'error', '2'], ['99', 'post', 'indexed', '1']]);
        self::assertSame([['Main Site', 1, 'post', 'indexed', '5'], [$GLOBALS['uams_test_sites'][4], 4, 'page', 'error', '2'], ['(site not found)', 99, 'post', 'indexed', '1']], $rows);
    }
    public function testEachSiteIsLookedUpOnce(): void {
        SiteNames::prefix([['1', 'a'], ['1', 'b'], ['4', 'c'], ['1', 'd'], ['99', 'e'], ['99', 'f']]);
        self::assertSame([1, 4, 99], $GLOBALS['uams_test_blog_lookups']);
    }
    public function testSiteNamesAreEscapedInTheTable(): void {
        $table = new ReflectionMethod(Admin::class, 'table');
        ob_start();
        $table->invoke(null, ['Site Name', 'Site ID', 'Count'], SiteNames::prefix([['4', '2']]));
        $html = ob_get_clean();
        self::assertStringContainsString('<td>Clinics &lt;b&gt;&amp;&lt;/b&gt; &quot;Care&quot;</td><td>4</td><td>2</td>', $html);
        self::assertStringNotContainsString('<b>', $html);
    }
}
