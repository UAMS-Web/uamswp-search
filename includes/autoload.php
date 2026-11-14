<?php

// Load plugin classes, and the bundled PDF parser under its private namespace, on first use
spl_autoload_register(static function (string $class): void {
    // Map the class name to its file
    if (str_starts_with($class, 'UAMS_Search\\')) {
        $file = __DIR__.'/'.substr($class, 12).'.php';
    } elseif (str_starts_with($class, 'UAMS_Search_Vendor\\PdfParser\\')) {
        $file = __DIR__.'/../lib/pdfparser/src/Smalot/PdfParser/'.str_replace('\\', '/', substr($class, 29)).'.php';
    } else {
        return;
    }

    // Load the file when it exists
    if (is_file($file)) {
        require_once $file;
    }
});
