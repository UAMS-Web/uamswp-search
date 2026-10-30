<?php
use PHPUnit\Framework\TestCase;
use UAMS_Search\Pdf;

function get_attached_file($id) { return dirname(__DIR__) . '/fixtures/text.pdf'; }
function wp_get_upload_dir() { return ['basedir' => dirname(__DIR__) . '/fixtures']; }

final class PdfTest extends TestCase {
    public function testCachedExtractionKeepsFilenameAndSkipsWorker(): void {
        $GLOBALS['uams_test_options'] = [];
        $GLOBALS['uams_test_filters'] = ['uams_search_pdftotext' => [static function () { throw new RuntimeException('Cache should skip extraction'); }]];
        $path = realpath(get_attached_file(42));
        $sig = hash('sha256', $path . '|' . filesize($path) . '|' . filemtime($path));
        try {
            $cached = Pdf::extract(new WP_Post(), ['file_signature' => $sig, 'status' => 'ready', 'pdf' => 'cached text', 'error' => '']);
            self::assertSame('cached text', $cached['pdf']);
            self::assertSame('text', $cached['fields']);
        } finally { $GLOBALS['uams_test_filters'] = []; }
    }
    public function testPopplerExtractsRealPdf(): void {
        $bin = Pdf::binary('pdftotext');
        if (!$bin) { self::markTestSkipped('Poppler is not installed'); }
        $text = Pdf::process([$bin, '-enc', 'UTF-8', dirname(__DIR__) . '/fixtures/text.pdf', '-'], 5);
        self::assertStringContainsString('Cardiology PDF sentinel', $text);
    }
    public function testBundledPhpParserExtractsRealPdf(): void {
        $text = Pdf::process([PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'error_reporting=' . (E_ALL & ~E_DEPRECATED), dirname(__DIR__) . '/scripts/pdf-worker.php', dirname(__DIR__) . '/fixtures/text.pdf'], 5);
        self::assertStringContainsString('Cardiology PDF sentinel', $text);
        self::assertStringNotContainsString('Deprecated', $text);
    }
    public function testImageOnlyPdfHasNoText(): void {
        $text = Pdf::process([PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'error_reporting=' . (E_ALL & ~E_DEPRECATED), dirname(__DIR__) . '/scripts/pdf-worker.php', dirname(__DIR__) . '/fixtures/scanned.pdf'], 5);
        self::assertSame('', trim($text));
    }
    public function testTimeoutActuallyStopsChild(): void {
        $start = microtime(true);
        try { Pdf::process([PHP_BINARY, '-r', 'usleep(3000000); echo "too late";'], 1); self::fail('Child was not stopped'); }
        catch (RuntimeException $e) { self::assertStringContainsString('timed out', $e->getMessage()); }
        self::assertLessThan(2.5, microtime(true) - $start);
    }
    public function testMemoryFailureDoesNotKillParent(): void {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('PDF extractor failed');
        Pdf::process([PHP_BINARY, '-d', 'memory_limit=16M', '-d', 'display_errors=stderr', '-r', '$s = str_repeat("x", 64 * 1024 * 1024);'], 5);
    }
}
