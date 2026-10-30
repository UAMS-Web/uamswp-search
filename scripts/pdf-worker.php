<?php
// Standalone child process. No WordPress bootstrap, requests, or parent-process memory use.
if (PHP_SAPI !== 'cli' || empty($argv[1]) || !is_file($argv[1])) { exit(1); }
require dirname(__DIR__) . '/includes/autoload.php';
try {
    $parser = new \UAMS_Search_Vendor\PdfParser\Parser();
    echo $parser->parseFile($argv[1])->getText();
} catch (\Throwable $e) {
    fwrite(STDERR, $e->getMessage()); exit(1);
}
