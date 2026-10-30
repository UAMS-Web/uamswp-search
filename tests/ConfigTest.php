<?php
use PHPUnit\Framework\TestCase;
use UAMS_Search\Config;

function wp_get_environment_type() { return $GLOBALS['uams_test_environment'] ?? 'production'; }
function get_sites($args) {
    $sites = [1 => ['public'=>1,'archived'=>0,'spam'=>0,'deleted'=>0], 4 => ['public'=>0,'archived'=>0,'spam'=>0,'deleted'=>0], 5 => ['public'=>0,'archived'=>1,'spam'=>0,'deleted'=>0], 6 => ['public'=>0,'archived'=>0,'spam'=>1,'deleted'=>0], 7 => ['public'=>0,'archived'=>0,'spam'=>0,'deleted'=>1]];
    return array_keys(array_filter($sites, static function ($site) use ($args) { foreach (['public','archived','spam','deleted'] as $key) { if (isset($args[$key]) && $site[$key] !== $args[$key]) { return false; } } return true; }));
}

final class ConfigTest extends TestCase {
    protected function setUp(): void { $GLOBALS['uams_test_multisite'] = true; }
    protected function tearDown(): void { unset($GLOBALS['uams_test_multisite'], $GLOBALS['uams_test_environment'], $GLOBALS['uams_test_logged_in']); }
    public function testProductionNeverIncludesNoIndexSites(): void {
        $GLOBALS['uams_test_environment'] = 'production'; $GLOBALS['uams_test_logged_in'] = true;
        self::assertSame([1], Config::public_sites());
    }
    public function testAnonymousLocalVisitorsStillExcludeNoIndexSites(): void {
        $GLOBALS['uams_test_environment'] = 'local'; $GLOBALS['uams_test_logged_in'] = false;
        self::assertSame([1], Config::public_sites());
    }
    public function testSignedInLocalPreviewIncludesOnlyHealthySites(): void {
        $GLOBALS['uams_test_environment'] = 'local'; $GLOBALS['uams_test_logged_in'] = true;
        self::assertSame([1,4], Config::public_sites());
    }
}
