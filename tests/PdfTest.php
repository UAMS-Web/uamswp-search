<?php

namespace {

    function get_attached_file($id)
    {
        return $GLOBALS['uams_test_attached'] ?? __DIR__.'/../fixtures/text.pdf';
    }

    function wp_get_upload_dir()
    {
        return ['basedir' => $GLOBALS['uams_test_uploads'] ?? __DIR__.'/../fixtures'];
    }
}

namespace UAMS_Search\Tests {
    use PHPUnit\Framework\TestCase;
    use RuntimeException;
    use UAMS_Search\Pdf;
    use WP_Post;

    final class PdfTest extends TestCase
    {
        protected function tearDown(): void
        {
            unset($GLOBALS['uams_test_attached'], $GLOBALS['uams_test_uploads']);
            $GLOBALS['uams_test_options'] = [];
            $GLOBALS['uams_test_filters'] = [];
        }

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
            $GLOBALS['uams_test_options'] = [];
            $GLOBALS['uams_test_filters'] = ['uams_search_pdftotext' => [static fn (): array => ['not', 'a', 'path']]];
            try {
                $row = Pdf::extract(new WP_Post, null);
                self::assertSame('ready', $row['status']);
                self::assertStringContainsString('Cardiology PDF sentinel', $row['pdf']);
            } catch (RuntimeException $runtimeException) {
                // Without Poppler or a PHP binary at PHP_BINDIR/php, the documented error names the fallback filter.
                self::assertStringContainsString('set uams_search_php_binary', $runtimeException->getMessage());
            } finally {
                $GLOBALS['uams_test_filters'] = [];
            }
        }

        public function test_the_default_php_binary_ends_in_exe_on_windows_only(): void
        {
            // Nothing is executable at these paths, so the build folder's path comes back
            self::assertSame('/no/such/dir/php.exe', Pdf::php_binary('Windows', '/no/such/dir', '/also/missing/php-cgi.exe'));
            self::assertSame('/no/such/dir/php', Pdf::php_binary('Linux', '/no/such/dir', '/also/missing/php-fpm'));
        }

        public function test_the_default_php_binary_falls_back_to_the_folder_of_the_running_binary(): void
        {
            $dir = str_replace('\\', '/', sys_get_temp_dir()).'/uams-php-binary-'.bin2hex(random_bytes(4));
            mkdir($dir);
            $name = PHP_OS_FAMILY === 'Windows' ? 'php.exe' : 'php';
            try {
                // The build folder is empty and the running binary is a sibling of the real one, as under php-cgi or PHP-FPM
                // Windows tests the file's contents as well as its name, so it gets a real program
                PHP_OS_FAMILY === 'Windows' ? copy(PHP_BINARY, $dir.'/'.$name) : file_put_contents($dir.'/'.$name, '');
                chmod($dir.'/'.$name, 0755);
                self::assertSame($dir.'/'.$name, Pdf::php_binary(PHP_OS_FAMILY, '/no/such/dir', str_replace('/', DIRECTORY_SEPARATOR, $dir).DIRECTORY_SEPARATOR.'php-cgi'));
                // The build folder wins when its program is executable
                self::assertSame($dir.'/'.$name, Pdf::php_binary(PHP_OS_FAMILY, $dir, '/also/missing/php-fpm'));
            } finally {
                unlink($dir.'/'.$name);
                rmdir($dir);
            }
        }

        public function test_the_default_php_binary_of_the_running_install_is_executable(): void
        {
            self::assertTrue(is_executable(Pdf::php_binary()), Pdf::php_binary());
        }

        public function test_the_bundled_parser_runs_through_the_default_php_binary_when_poppler_fails(): void
        {
            $GLOBALS['uams_test_options'] = [];
            // A pdftotext that exits non-zero (the PHP binary, given Poppler's arguments) sends the extraction to the bundled parser, with no php_binary filter set
            $GLOBALS['uams_test_filters'] = ['uams_search_pdftotext' => [static fn (): string => PHP_BINARY]];
            $row = Pdf::extract(new WP_Post, null);
            self::assertSame('ready', $row['status']);
            self::assertStringContainsString('Cardiology PDF sentinel', $row['pdf']);
        }

        public function test_a_php_binary_filter_returning_null_does_not_raise_a_type_error(): void
        {
            $GLOBALS['uams_test_options'] = [];
            $GLOBALS['uams_test_filters'] = [
                'uams_search_pdftotext' => [static fn (): string => PHP_BINARY],
                'uams_search_php_binary' => [static fn (): null => null],
            ];
            try {
                $row = Pdf::extract(new WP_Post, null);
                self::assertSame('ready', $row['status']);
            } catch (RuntimeException $runtimeException) {
                // PHP_BINARY stands in for a pdftotext that exits non-zero. Where the default PHP binary is not at PHP_BINDIR/php, the documented error names the filter.
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

        public function test_a_missing_file_is_refused(): void
        {
            $GLOBALS['uams_test_attached'] = __DIR__.'/../fixtures/does-not-exist.pdf';
            $this->expectExceptionMessage('PDF file is missing or outside uploads');
            Pdf::extract(new WP_Post, null);
        }

        public function test_a_file_outside_the_uploads_folder_is_refused(): void
        {
            $GLOBALS['uams_test_attached'] = __FILE__;
            $this->expectExceptionMessage('PDF file is missing or outside uploads');
            Pdf::extract(new WP_Post, null);
        }

        public function test_a_file_over_the_size_limit_is_refused(): void
        {
            $GLOBALS['uams_test_options']['uams_search_settings'] = ['pdf_max_mb' => 0.0001];
            $this->expectExceptionMessage('PDF exceeds size limit');
            Pdf::extract(new WP_Post, null);
        }

        public function test_the_bundled_parser_runs_when_poppler_is_unavailable(): void
        {
            $GLOBALS['uams_test_options']['uams_search_settings'] = ['pdf_timeout' => 60];
            $GLOBALS['uams_test_filters'] = [
                'uams_search_pdftotext' => [static fn (): string => ''],
                'uams_search_php_binary' => [static fn (): string => PHP_BINARY],
            ];
            $row = Pdf::extract(new WP_Post, null);

            self::assertSame('ready', $row['status']);
            self::assertStringContainsString('Cardiology PDF sentinel', $row['pdf']);
        }

        public function test_extraction_stops_when_poppler_fails_and_no_time_is_left(): void
        {
            $GLOBALS['uams_test_options']['uams_search_settings'] = ['pdf_timeout' => 1];
            $GLOBALS['uams_test_filters'] = ['uams_search_pdftotext' => [static fn (): string => PHP_BINARY]];
            $this->expectExceptionMessage('PDF extraction timed out');
            Pdf::extract(new WP_Post, null);
        }

        public function test_the_fallback_needs_an_executable_php(): void
        {
            $GLOBALS['uams_test_options']['uams_search_settings'] = ['pdf_timeout' => 60];
            $GLOBALS['uams_test_filters'] = [
                'uams_search_pdftotext' => [static fn (): string => ''],
                'uams_search_php_binary' => [static fn (): string => '/nonexistent/php'],
            ];
            $this->expectExceptionMessage('PDF fallback needs an executable PHP CLI; set uams_search_php_binary. Poppler unavailable');
            Pdf::extract(new WP_Post, null);
        }

        public function test_binary_finds_an_executable_and_reports_a_missing_one_as_null(): void
        {
            $found = Pdf::binary('sh');
            self::assertNotNull($found);
            self::assertTrue(is_executable($found));
            self::assertNull(Pdf::binary('uams-search-no-such-program'));
        }

        public function test_binary_looks_for_the_exe_file_on_windows_only(): void
        {
            $dir = sys_get_temp_dir().'/uams-search-poppler-'.bin2hex(random_bytes(4));
            mkdir($dir);
            $program = $dir.'/pdftotext.exe';
            // Windows treats only a real executable file as executable; elsewhere the mode decides
            if (PHP_OS_FAMILY === 'Windows') {
                copy(PHP_BINARY, $program);
            } else {
                file_put_contents($program, '');
                chmod($program, 0755);
            }

            try {
                self::assertSame($program, Pdf::binary('pdftotext', 'Windows', $dir));
                self::assertSame($program, Pdf::binary('pdftotext.exe', 'Windows', $dir), 'A name that already ends in .exe is not doubled.');
                self::assertSame($program, Pdf::binary('pdftotext', 'Windows', '/no/such/folder'.PATH_SEPARATOR.$dir), 'Each folder of the path is searched.');
                self::assertNull(Pdf::binary('pdftotext', 'Linux', $dir), 'Elsewhere the bare name is searched, and only that.');
                self::assertNull(Pdf::binary('pdftotext', 'Windows', '/no/such/folder'));
            } finally {
                unlink($program);
                rmdir($dir);
            }
        }

        public function test_too_much_error_output_stops_the_command(): void
        {
            $this->expectExceptionMessage('PDF extraction output limit exceeded');
            Pdf::process([PHP_BINARY, '-r', 'fwrite(STDERR, str_repeat("x", 70000)); usleep(3000000);'], 10);
        }

        public function test_a_clean_exit_returns_what_the_command_printed(): void
        {
            self::assertSame('hello', Pdf::process([PHP_BINARY, '-r', 'echo "hello";'], 5));
        }

        public function test_a_missing_uploads_folder_refuses_every_file(): void
        {
            $GLOBALS['uams_test_uploads'] = __DIR__.'/../fixtures/no-such-folder';
            $this->expectExceptionMessage('PDF file is missing or outside uploads');
            Pdf::extract(new WP_Post, null);
        }

        public function test_a_sibling_folder_sharing_the_uploads_prefix_is_outside_uploads(): void
        {
            $base = sys_get_temp_dir().'/uams-search-pdf-'.getmypid();
            mkdir($base.'/up', 0777, true);
            mkdir($base.'/up-evil', 0777, true);
            copy(__DIR__.'/../fixtures/text.pdf', $base.'/up-evil/a.pdf');
            $GLOBALS['uams_test_uploads'] = $base.'/up';
            $GLOBALS['uams_test_attached'] = $base.'/up-evil/a.pdf';
            try {
                Pdf::extract(new WP_Post, null);
                self::fail('A sibling folder must be refused.');
            } catch (RuntimeException $runtimeException) {
                self::assertSame('PDF file is missing or outside uploads', $runtimeException->getMessage());
            } finally {
                unlink($base.'/up-evil/a.pdf');
                rmdir($base.'/up-evil');
                rmdir($base.'/up');
                rmdir($base);
            }
        }

        public function test_a_file_exactly_at_the_size_limit_is_accepted(): void
        {
            $size = filesize(__DIR__.'/../fixtures/text.pdf');
            $GLOBALS['uams_test_options']['uams_search_settings'] = ['pdf_max_mb' => $size / 1048576, 'pdf_timeout' => 60];
            $GLOBALS['uams_test_filters'] = ['uams_search_php_binary' => [static fn (): string => PHP_BINARY]];

            self::assertSame($size, Pdf::extract(new WP_Post, null)['file_size']);
        }

        public function test_cached_text_is_reused_only_for_a_finished_extraction(): void
        {
            $GLOBALS['uams_test_options']['uams_search_settings'] = ['pdf_timeout' => 60];
            $GLOBALS['uams_test_filters'] = ['uams_search_php_binary' => [static fn (): string => PHP_BINARY]];
            $path = realpath(get_attached_file(42));
            $sig = hash('sha256', $path.'|'.filesize($path).'|'.filemtime($path));

            $row = Pdf::extract(new WP_Post, ['file_signature' => $sig, 'status' => 'failed', 'pdf' => 'stale text', 'error' => 'x']);
            self::assertStringContainsString('Cardiology PDF sentinel', $row['pdf']);

            $row = Pdf::extract(new WP_Post, ['file_signature' => $sig, 'status' => 'scanned', 'pdf' => '', 'error' => 'kept']);
            self::assertSame('scanned', $row['status']);
            self::assertSame('kept', $row['error']);
        }

        public function test_a_pdf_without_a_text_layer_is_marked_scanned(): void
        {
            $GLOBALS['uams_test_attached'] = __DIR__.'/../fixtures/scanned.pdf';
            $GLOBALS['uams_test_options']['uams_search_settings'] = ['pdf_timeout' => 60];
            $GLOBALS['uams_test_filters'] = ['uams_search_php_binary' => [static fn (): string => PHP_BINARY]];
            $row = Pdf::extract(new WP_Post, null);

            self::assertSame('scanned', $row['status']);
            self::assertSame('', $row['pdf']);
            self::assertSame('No text layer; matched by title and filename only.', $row['error']);
            self::assertSame('scanned', $row['fields']);
        }

        public function test_an_empty_path_entry_is_not_searched_as_the_root_folder(): void
        {
            $path = getenv('PATH');
            putenv('PATH=');
            try {
                self::assertNull(Pdf::binary('bin'));
            } finally {
                putenv('PATH='.$path);
            }
        }

        public function test_a_command_that_cannot_start_is_reported(): void
        {
            // proc_open warns when the program is missing; the warning is part of what is pinned here
            $warning = '';
            set_error_handler(static function (int $no, string $msg) use (&$warning): bool {
                $warning = $msg;

                return true;
            });
            try {
                Pdf::process(['/no/such/uams-search-program'], 5);
                self::fail('A program that cannot start must raise.');
            } catch (RuntimeException $runtimeException) {
                self::assertSame('Could not start PDF extractor', $runtimeException->getMessage());
            } finally {
                restore_error_handler();
            }

            self::assertNotSame('', $warning);
        }

        public function test_the_worker_exits_with_1_without_a_readable_file_argument(): void
        {
            $worker = __DIR__.'/../scripts/pdf-worker.php';
            $none = $this->worker([PHP_BINARY, $worker]);
            self::assertSame(1, $none['exit']);
            self::assertSame('', $none['out'].$none['err']);

            $missing = $this->worker([PHP_BINARY, $worker, __DIR__.'/../fixtures/does-not-exist.pdf']);
            self::assertSame(1, $missing['exit']);
            self::assertSame('', $missing['out'].$missing['err']);

            $empty = $this->worker([PHP_BINARY, $worker, '']);
            self::assertSame(1, $empty['exit']);
        }

        public function test_the_worker_reports_a_corrupt_pdf_on_standard_error_and_exits_with_1(): void
        {
            $file = sys_get_temp_dir().'/uams-search-corrupt-'.bin2hex(random_bytes(4)).'.pdf';
            file_put_contents($file, 'this is not a PDF');
            try {
                $run = $this->worker([PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'error_reporting='.(E_ALL & ~E_DEPRECATED), __DIR__.'/../scripts/pdf-worker.php', $file]);
            } finally {
                unlink($file);
            }

            self::assertSame(1, $run['exit']);
            self::assertSame('', $run['out']);
            self::assertNotSame('', $run['err']);
        }

        public function test_the_worker_prints_the_text_of_a_pdf_and_exits_with_0(): void
        {
            $run = $this->worker([PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'error_reporting='.(E_ALL & ~E_DEPRECATED), __DIR__.'/../scripts/pdf-worker.php', __DIR__.'/../fixtures/text.pdf']);
            self::assertSame(0, $run['exit']);
            self::assertStringContainsString('Cardiology PDF sentinel', $run['out']);
            self::assertSame('', $run['err']);
        }

        public function test_extraction_refuses_to_run_where_proc_open_is_disabled(): void
        {
            // A child PHP with proc_open disabled loads the class and calls it, as a locked-down host would
            $code = 'require '.var_export(realpath(__DIR__.'/../includes/autoload.php'), true).';'
                .('try { '.Pdf::class.'::process(["x"], 1); echo "no error"; } catch (RuntimeException $e) { echo $e->getMessage(); }');
            $run = $this->worker([PHP_BINARY, '-d', 'disable_functions=proc_open', '-r', $code]);
            self::assertSame(0, $run['exit']);
            self::assertSame('PDF extraction requires proc_open for enforced time and memory limits', $run['out']);
        }

        public function test_the_worker_script_prints_the_text_when_included_in_this_process(): void
        {
            // Covers the script's own lines, which a child process cannot report back
            $argv = ['pdf-worker.php', __DIR__.'/../fixtures/text.pdf'];
            ob_start();
            try {
                include __DIR__.'/../scripts/pdf-worker.php';
            } finally {
                $text = (string) ob_get_clean();
            }

            self::assertStringContainsString('Cardiology PDF sentinel', $text);
        }

        /**
         * Run a command to the end and return its exit code and both output streams.
         *
         * @param  array<int, string>  $command
         * @return array{exit: int, out: string, err: string}
         */
        private function worker(array $command): array
        {
            $pipes = [];
            $proc = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($proc);
            $out = (string) stream_get_contents($pipes[1]);
            $err = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);

            return ['exit' => proc_close($proc), 'out' => $out, 'err' => $err];
        }
    }
}
