<?php

use PHPUnit\Framework\TestCase;
use UAMS_Search\Pdf;

function get_attached_file($id)
{
    return __DIR__.'/../fixtures/text.pdf';
}

function wp_get_upload_dir()
{
    return ['basedir' => __DIR__.'/../fixtures'];
}

final class PdfTest extends TestCase
{
    public function test_cached_extraction_keeps_filename_and_skips_worker(): void
    {
        $GLOBALS['uams_test_options'] = [];
        $GLOBALS['uams_test_filters'] = ['uams_search_pdftotext' => [static function (): never {
            throw new RuntimeException('Cache should skip extraction');
        }]];
        $path = realpath(get_attached_file(42));
        $sig = hash('sha256', $path.'|'.filesize($path).'|'.filemtime($path));
        try {
            $cached = Pdf::extract(new WP_Post, ['file_signature' => $sig, 'status' => 'ready', 'pdf' => 'cached text', 'error' => '']);
            self::assertSame('cached text', $cached['pdf']);
            self::assertSame('text', $cached['fields']);
        } finally {
            $GLOBALS['uams_test_filters'] = [];
        }
    }

    public function test_a_pdftotext_filter_returning_an_array_uses_the_default_binary(): void
    {
        if (! Pdf::binary('pdftotext')) {
            self::markTestSkipped('Poppler is not installed');
        }

        $GLOBALS['uams_test_options'] = [];
        $GLOBALS['uams_test_filters'] = ['uams_search_pdftotext' => [static fn (): array => ['not', 'a', 'path']]];
        try {
            $row = Pdf::extract(new WP_Post, null);
            self::assertSame('ready', $row['status']);
            self::assertStringContainsString('Cardiology PDF sentinel', $row['pdf']);
        } finally {
            $GLOBALS['uams_test_filters'] = [];
        }
    }

    public function test_a_php_binary_filter_returning_null_does_not_raise_a_type_error(): void
    {
        $GLOBALS['uams_test_options'] = [];
        $GLOBALS['uams_test_filters'] = [
            'uams_search_pdftotext' => [static fn (): string => '/nonexistent/pdftotext'],
            'uams_search_php_binary' => [static fn (): null => null],
        ];
        try {
            $row = Pdf::extract(new WP_Post, null);
            self::assertSame('ready', $row['status']);
        } catch (RuntimeException $runtimeException) {
            // Where the default PHP binary is not at PHP_BINDIR/php, the documented error names the filter.
            self::assertStringContainsString('set uams_search_php_binary', $runtimeException->getMessage());
        } finally {
            $GLOBALS['uams_test_filters'] = [];
        }
    }

    public function test_poppler_extracts_real_pdf(): void
    {
        $bin = Pdf::binary('pdftotext');
        if (! $bin) {
            self::markTestSkipped('Poppler is not installed');
        }

        $text = Pdf::process([$bin, '-enc', 'UTF-8', __DIR__.'/../fixtures/text.pdf', '-'], 5);
        self::assertStringContainsString('Cardiology PDF sentinel', $text);
    }

    public function test_bundled_php_parser_extracts_real_pdf(): void
    {
        $text = Pdf::process([PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'error_reporting='.(E_ALL & ~E_DEPRECATED), __DIR__.'/../scripts/pdf-worker.php', __DIR__.'/../fixtures/text.pdf'], 5);
        self::assertStringContainsString('Cardiology PDF sentinel', $text);
        self::assertStringNotContainsString('Deprecated', $text);
    }

    public function test_image_only_pdf_has_no_text(): void
    {
        $text = Pdf::process([PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'error_reporting='.(E_ALL & ~E_DEPRECATED), __DIR__.'/../scripts/pdf-worker.php', __DIR__.'/../fixtures/scanned.pdf'], 5);
        self::assertSame('', trim($text));
    }

    public function test_timeout_actually_stops_child(): void
    {
        $start = microtime(true);
        try {
            Pdf::process([PHP_BINARY, '-r', 'usleep(3000000); echo "too late";'], 1);
            self::fail('Child was not stopped');
        } catch (RuntimeException $runtimeException) {
            self::assertStringContainsString('timed out', $runtimeException->getMessage());
        }

        self::assertLessThan(2.5, microtime(true) - $start);
    }

    public function test_memory_failure_does_not_kill_parent(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('PDF extractor failed');
        Pdf::process([PHP_BINARY, '-d', 'memory_limit=16M', '-d', 'display_errors=stderr', '-r', '$s = str_repeat("x", 64 * 1024 * 1024);'], 5);
    }
}
