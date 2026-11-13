<?php

use UAMS_Search_Vendor\PdfParser\Parser;

/**
 * Extracts the text of one PDF in a child process and prints it to standard output.
 *
 * Usage: `php pdf-worker.php <path to PDF>`. Exits with 1 and prints the reason to standard error on failure.
 */

// Standalone child process. No WordPress bootstrap, requests, or parent-process memory use.
if (PHP_SAPI !== 'cli' || empty($argv[1]) || ! is_file($argv[1])) {
    exit(1);
}
// Load the plugin's classes and the bundled parser
require dirname(__DIR__).'/includes/autoload.php';
// Parse the file and print its text
try {
    $parser = new Parser;
    echo $parser->parseFile($argv[1])->getText();
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage());
    exit(1);
}
