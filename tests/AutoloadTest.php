<?php

namespace {

}

namespace UAMS_Search\Tests {
    use PHPUnit\Framework\TestCase;
    use UAMS_Search\Text;

    /** Pins for the class loader in `includes/autoload.php` (#5). */
    final class AutoloadTest extends TestCase
    {
        public function test_plugin_classes_load_from_includes(): void
        {
            self::assertTrue(class_exists(Text::class));
            self::assertFalse(class_exists('UAMS_Search\NoSuchClass'));
        }

        public function test_the_bundled_pdf_parser_loads_under_its_private_namespace(): void
        {
            self::assertTrue(class_exists('UAMS_Search_Vendor\PdfParser\Parser'));
            self::assertFalse(class_exists('UAMS_Search_Vendor\PdfParser\NoSuchClass'));
        }

        public function test_a_nested_bundled_parser_class_loads_from_its_folder(): void
        {
            $class = 'UAMS_Search_Vendor\PdfParser\Encoding\WinAnsiEncoding';
            if (! class_exists($class)) {
                self::fail('The nested bundled parser class did not load.');
            }

            // The class name is a string PHPStan cannot resolve, so the loaded file comes from the included-files list.
            self::assertContains(realpath(__DIR__.'/../lib/pdfparser/src/Smalot/PdfParser/Encoding/WinAnsiEncoding.php'), array_map(realpath(...), get_included_files()));
        }

        public function test_classes_in_other_namespaces_are_left_to_other_loaders(): void
        {
            self::assertFalse(class_exists('Other_Vendor\Thing'));
            self::assertFalse(class_exists('Smalot\PdfParser\Parser', false));
        }
    }
}
