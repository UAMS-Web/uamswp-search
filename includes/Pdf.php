<?php
namespace UAMS_Search;

final class Pdf {
    public static function extract(\WP_Post $post, ?array $old): array {
        $path = get_attached_file($post->ID); $uploads = wp_get_upload_dir();
        $real = $path ? realpath($path) : false; $root = realpath($uploads['basedir']);
        if (!$real || !$root || !str_starts_with($real, $root . DIRECTORY_SEPARATOR) || !is_readable($real)) { throw new \RuntimeException('PDF file is missing or outside uploads'); }
        $size = filesize($real); $config = Config::get();
        if ($size > (float)$config['pdf_max_mb'] * 1024 * 1024) { throw new \RuntimeException('PDF exceeds size limit'); }
        $sig = hash('sha256', $real . '|' . $size . '|' . filemtime($real));
        if ($old && $old['file_signature'] === $sig && in_array($old['status'], ['ready', 'scanned'], true)) {
            return ['pdf' => $old['pdf'], 'file_signature' => $sig, 'file_size' => $size, 'status' => $old['status'], 'error' => $old['error'], 'fields' => Text::plain(pathinfo($real, PATHINFO_FILENAME))];
        }
        $binary = apply_filters('uams_search_pdftotext', self::binary('pdftotext'));
        $seconds = min(120, max(1, (int)$config['pdf_timeout']));
        $started = microtime(true);
        try {
            if (!$binary) { throw new \RuntimeException('Poppler unavailable'); }
            $text = self::process([$binary, '-enc', 'UTF-8', '-nopgbrk', $real, '-'], $seconds);
        } catch (\Throwable $e) {
            $remaining = (int)floor($seconds - (microtime(true) - $started));
            if ($remaining < 1) { throw new \RuntimeException('PDF extraction timed out', 0, $e); }
            $php = apply_filters('uams_search_php_binary', PHP_BINDIR . '/php');
            if (!is_executable($php)) { throw new \RuntimeException('PDF fallback needs an executable PHP CLI; set uams_search_php_binary. ' . $e->getMessage()); }
            $text = self::process([$php, '-d', 'memory_limit=128M', '-d', 'display_errors=stderr', '-d', 'error_reporting=' . (E_ALL & ~E_DEPRECATED), '-d', 'max_execution_time=' . $remaining, dirname(__DIR__) . '/scripts/pdf-worker.php', $real], $remaining);
        }
        $plain = Text::plain($text);
        return ['pdf' => $plain, 'file_signature' => $sig, 'file_size' => $size, 'status' => $plain === '' ? 'scanned' : 'ready', 'error' => $plain === '' ? 'No text layer; matched by title and filename only.' : '', 'fields' => Text::plain(pathinfo($real, PATHINFO_FILENAME))];
    }
    public static function binary(string $name): ?string {
        foreach (array_merge(explode(PATH_SEPARATOR, getenv('PATH') ?: ''), ['/usr/bin', '/usr/local/bin', '/opt/homebrew/bin']) as $dir) {
            if ($dir !== '' && is_executable($dir . '/' . $name)) { return $dir . '/' . $name; }
        }
        return null;
    }
    public static function process(array $command, int $timeout): string {
        if (!function_exists('proc_open')) { throw new \RuntimeException('PDF extraction requires proc_open for enforced time and memory limits'); }
        $pipes = []; $proc = proc_open($command, [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']], $pipes);
        if (!is_resource($proc)) { throw new \RuntimeException('Could not start PDF extractor'); }
        fclose($pipes[0]); stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false);
        $start = microtime(true); $out = ''; $err = ''; $exit = -1;
        try {
            do {
                $out .= stream_get_contents($pipes[1]); $err .= stream_get_contents($pipes[2]);
                if (strlen($out) > 16 * 1024 * 1024 || strlen($err) > 65536) { throw new \RuntimeException('PDF extraction output limit exceeded'); }
                if (microtime(true) - $start > $timeout) { throw new \RuntimeException('PDF extraction timed out'); }
                $status = proc_get_status($proc);
                if (!$status['running']) { $exit = $status['exitcode']; $out .= stream_get_contents($pipes[1]); break; }
                usleep(10000);
            } while (true);
        } finally {
            $status = proc_get_status($proc);
            if ($status['running']) { proc_terminate($proc, 9); }
            fclose($pipes[1]); fclose($pipes[2]); $closed = proc_close($proc);
            if ($exit < 0) { $exit = $closed; }
        }
        if ($exit !== 0) { throw new \RuntimeException('PDF extractor failed: ' . mb_substr(Text::plain($err), 0, 500)); }
        return $out;
    }
}
