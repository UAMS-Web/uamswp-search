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

        public function test_classes_in_other_namespaces_are_left_to_other_loaders(): void
        {
            self::assertFalse(class_exists('Other_Vendor\Thing'));
            self::assertFalse(class_exists('Smalot\PdfParser\Parser', false));
        }
    }
}
