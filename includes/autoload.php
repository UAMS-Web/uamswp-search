<?php
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'UAMS_Search\\')) {
        $file = __DIR__ . '/' . substr($class, 12) . '.php';
    } elseif (str_starts_with($class, 'UAMS_Search_Vendor\\PdfParser\\')) {
        $file = dirname(__DIR__) . '/lib/pdfparser/src/Smalot/PdfParser/' . str_replace('\\', '/', substr($class, 29)) . '.php';
    } else {
        return;
    }
    if (is_file($file)) { require_once $file; }
});
