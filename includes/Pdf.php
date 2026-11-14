<?php

namespace UAMS_Search;

use RuntimeException;
use Throwable;
use WP_Post;

/** Extracts the text of a PDF attachment with Poppler, or a bundled PHP parser. */
final class Pdf
{
    /**
     * Extract the text of a PDF attachment, reusing the earlier result when the
     * file has not changed.
     *
     * @param  WP_Post  $post  The PDF attachment.
     * @param  array<string, mixed>|null  $old  The attachment's earlier index row.
     * @return array{pdf: string, file_signature: string, file_size: int|false,
     *         status: string, error: string, fields: string} The text, the file
     *         signature and size, `ready` or `scanned` (no text layer), any
     *         note, and the file name as plain text.
     *
     * @throws RuntimeException When the file is missing, outside uploads or
     *                          over the size limit, or every extractor fails or times out.
     */
    public static function extract(
        WP_Post $post,
        ?array $old
    ): array {
        // Find the file and check it is a readable file inside uploads
        $path = get_attached_file($post->ID);
        $uploads = wp_get_upload_dir();
        $real = $path ? realpath($path) : false;
        $root = realpath($uploads['basedir']);
        if (! $real || ! $root || ! str_starts_with($real, $root.DIRECTORY_SEPARATOR) || ! is_readable($real)) {
            throw new RuntimeException('PDF file is missing or outside uploads');
        }

        // Refuse a file over the size limit
        $size = filesize($real);
        if ($size > Config::number('pdf_max_mb') * 1024 * 1024) {
            throw new RuntimeException('PDF exceeds size limit');
        }

        // Reuse the earlier text when the file is unchanged
        $sig = hash('sha256', $real.'|'.$size.'|'.filemtime($real));
        if ($old && $old['file_signature'] === $sig && \in_array($old['status'], ['ready', 'scanned'], true)) {
            return ['pdf' => $old['pdf'], 'file_signature' => $sig, 'file_size' => $size, 'status' => $old['status'], 'error' => $old['error'], 'fields' => Text::plain(pathinfo($real, PATHINFO_FILENAME))];
        }

        // Find Poppler and the time limit
        $binary = Filters::string('uams_search_pdftotext', self::binary('pdftotext') ?? '');
        $seconds = min(120, max(1, (int) Config::number('pdf_timeout')));
        $started = microtime(true);
        // Try Poppler first
        try {
            if ($binary === '') {
                throw new RuntimeException('Poppler unavailable');
            }

            $text = self::process([$binary, '-enc', 'UTF-8', '-nopgbrk', $real, '-'], $seconds);
        } catch (Throwable $throwable) {
            // Check that time remains for the fallback
            $remaining = (int) floor($seconds - (microtime(true) - $started));
            if ($remaining < 1) {
                throw new RuntimeException('PDF extraction timed out', 0, $throwable);
            }

            // Find a PHP command to run the bundled parser
            $php = Filters::string('uams_search_php_binary', self::php_binary());
            if (! is_executable($php)) {
                throw new RuntimeException('PDF fallback needs an executable PHP CLI; set uams_search_php_binary. '.$throwable->getMessage(), $throwable->getCode(), $throwable);
            }

            // Run the bundled parser in a child process
            $text = self::process([$php, '-d', 'memory_limit=128M', '-d', 'display_errors=stderr', '-d', 'error_reporting='.(E_ALL & ~E_DEPRECATED), '-d', 'max_execution_time='.$remaining, __DIR__.'/../scripts/pdf-worker.php', $real], $remaining);
        }

        // Reduce the text to plain text and report the result
        $plain = Text::plain($text);

        return ['pdf' => $plain, 'file_signature' => $sig, 'file_size' => $size, 'status' => $plain === '' ? 'scanned' : 'ready', 'error' => $plain === '' ? 'No text layer; matched by title and filename only.' : '', 'fields' => Text::plain(pathinfo($real, PATHINFO_FILENAME))];
    }

    /**
     * The default PHP command line program that runs the bundled parser.
     *
     * Looks in the folder PHP was built for, then next to the running binary, which is where a
     * relocated install such as Herd keeps `php.exe` while `PHP_BINDIR` still names the build folder.
     * The running binary itself is not used: under PHP-FPM or Apache it is not the command line program.
     *
     * @param  string  $family  The operating system family, `Windows` or another.
     * @param  string  $bindir  The folder PHP was built to install its programs in.
     * @param  string  $running  The path of the running PHP binary.
     * @return string Path to `php`, or to `php.exe` on Windows: the first that is executable, otherwise the build folder's.
     */
    public static function php_binary(string $family = PHP_OS_FAMILY, string $bindir = PHP_BINDIR, string $running = PHP_BINARY): string
    {
        // Windows programs end in .exe, and a path without it cannot start
        $name = 'php'.($family === 'Windows' ? '.exe' : '');
        $default = $bindir.'/'.$name;
        foreach ([$default, \dirname(str_replace('\\', '/', $running)).'/'.$name] as $candidate) {
            if (is_executable($candidate)) {
                return $candidate;
            }
        }

        return $default;
    }

    /**
     * Find an executable on the server's path or the usual install folders.
     *
     * @param  string  $name  Program name, such as `pdftotext`.
     * @return string|null Full path, or null when it is not installed.
     */
    public static function binary(string $name): ?string
    {
        // Check each folder on the path, then the usual install folders
        foreach (array_merge(explode(PATH_SEPARATOR, getenv('PATH') ?: ''), ['/usr/bin', '/usr/local/bin', '/opt/homebrew/bin']) as $dir) {
            if ($dir !== '' && is_executable($dir.'/'.$name)) {
                return $dir.'/'.$name;
            }
        }

        return null;
    }

    /**
     * Run an extractor command and return what it prints, within time and
     * output limits.
     *
     * @param  array<int, string>  $command  Program and arguments.
     * @param  int  $timeout  Seconds the command may run.
     * @return string Standard output.
     *
     * @throws RuntimeException When the command cannot start, prints too
     *                          much, runs too long or exits with an error.
     */
    public static function process(
        array $command,
        int $timeout
    ): string {
        // Check that PHP may start processes
        if (! \function_exists('proc_open')) {
            throw new RuntimeException('PDF extraction requires proc_open for enforced time and memory limits');
        }

        // Start the command with pipes for input, output and errors
        $pipes = [];
        $proc = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (! \is_resource($proc)) {
            throw new RuntimeException('Could not start PDF extractor');
        }

        // Close the input and read the output without blocking
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $start = microtime(true);
        $out = '';
        $err = '';
        $exit = -1;
        // Collect the output until the command ends
        try {
            do {
                $out .= stream_get_contents($pipes[1]);
                $err .= stream_get_contents($pipes[2]);
                // Stop when the output grows past the limit
                if (\strlen($out) > 16 * 1024 * 1024 || \strlen($err) > 65536) {
                    throw new RuntimeException('PDF extraction output limit exceeded');
                }

                // Stop when the time limit passes
                if (microtime(true) - $start > $timeout) {
                    throw new RuntimeException('PDF extraction timed out');
                }

                // Finish when the command has ended
                $status = proc_get_status($proc);
                if (! $status['running']) {
                    $exit = $status['exitcode'];
                    $out .= stream_get_contents($pipes[1]);
                    break;
                }

                usleep(10000);
            } while (true);
        } finally {
            // Stop the command if it is still running
            $status = proc_get_status($proc);
            if ($status['running']) {
                proc_terminate($proc, 9);
            }

            // Close the pipes and collect the exit code
            fclose($pipes[1]);
            fclose($pipes[2]);
            $closed = proc_close($proc);
            if ($exit < 0) {
                $exit = $closed;
            }
        }

        // Report a failure with the start of its error output
        if ($exit !== 0) {
            throw new RuntimeException('PDF extractor failed: '.mb_substr(Text::plain($err), 0, 500));
        }

        return $out;
    }
}
