<?php

namespace {

    if (! function_exists('size_format')) {
        function size_format($size, $decimals = 0)
        {
            return round($size / 1024).' KB';
        }
    }
}

namespace UAMS_Search\Tests {
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\TestCase;
    use UAMS_Search\Integration;
    use UAMS_Search\LocationCard;
    use UAMS_Search\ProviderCard;

    final class ResultLabelsTest extends TestCase
    {
        protected function setUp(): void
        {
            $GLOBALS['uams_test_filters'] = [];
            $GLOBALS['uams_test_options'] = [];
            $_GET = [];
        }

        protected function tearDown(): void
        {
            $GLOBALS['uams_test_filters'] = [];
            unset($GLOBALS['wp_query']);
        }

        private function render(string $type, string $title = 'Heart care'): string
        {
            $result = (object) ['url' => 'https://example.test/a', 'title' => $title, 'post_type' => $type, 'file_size' => 2048, 'excerpt' => 'text'];
            $GLOBALS['wp_query'] = new class($result)
            {
                public function __construct(private readonly object $result) {}

                public function get($name): array
                {
                    return ['results' => [$this->result], 'total' => 1, 'pages' => 1, 'fallback' => false, 'suggestion' => null];
                }
            };

            ob_start();
            try {
                Integration::render();
            } finally {
                $html = (string) ob_get_clean();
            }

            return $html;
        }

        /** @return array<string, array{string, string}> */
        public static function labels(): array
        {
            return [
                'expertise' => ['expertise', 'Area of Expertise'],
                'condition' => ['condition', 'Condition'],
                'treatment' => ['treatment', 'Treatment or Procedure'],
                'post' => ['post', 'News'],
                'page' => ['page', 'Page'],
                'pdf' => ['attachment', 'PDF'],
            ];
        }

        #[DataProvider('labels')]
        public function test_each_type_is_labeled_above_the_title(string $type, string $label): void
        {
            $html = $this->render($type);

            self::assertStringContainsString('<p class="uams-search-label">'.$label.'</p><h3>', $html);
        }

        private function card(string $type): string
        {
            $card = ['image' => '', 'url' => 'https://example.test/a', 'name' => 'Heart care'];
            ob_start();
            try {
                if ($type === 'provider') {
                    ProviderCard::render($card + ['subtitle' => '']);
                } else {
                    LocationCard::render($card + ['parent' => null, 'notice' => null, 'address' => [], 'phones' => ['heading' => '', 'numbers' => []], 'directions' => '']);
                }
            } finally {
                $html = (string) ob_get_clean();
            }

            return $html;
        }

        /** @return array<string, array{string, string}> */
        public static function card_labels(): array
        {
            return [
                'provider' => ['provider', 'Person'],
                'location' => ['location', 'Clinical Location'],
            ];
        }

        #[DataProvider('card_labels')]
        public function test_each_card_is_labeled_above_its_title(string $type, string $label): void
        {
            self::assertStringContainsString('<div class="card-body"><p class="uams-search-label">'.$label.'</p><h3 class="card-title', $this->card($type));
        }

        public function test_a_card_label_comes_from_the_filter_and_is_escaped(): void
        {
            $GLOBALS['uams_test_filters']['uams_search_result_labels'][] = static fn (array $labels): array => ['provider' => '<b>Doctor</b>', 'location' => ''] + $labels;

            $provider = $this->card('provider');
            self::assertStringContainsString('&lt;b&gt;Doctor&lt;/b&gt;', $provider);
            self::assertStringNotContainsString('<b>', $provider);
            self::assertStringNotContainsString('uams-search-label', $this->card('location'));
        }

        public function test_a_type_with_no_label_shows_none(): void
        {
            self::assertStringNotContainsString('uams-search-label', $this->render('widget'));
        }

        public function test_a_pdf_keeps_its_file_size_after_the_title(): void
        {
            self::assertStringContainsString('Heart care <span>(2 KB)</span>', $this->render('attachment'));
        }

        public function test_labels_come_from_a_filter_and_are_escaped(): void
        {
            $GLOBALS['uams_test_filters']['uams_search_result_labels'][] = static fn (array $labels): array => ['page' => '<b>Web page</b>'] + $labels;

            $html = $this->render('page');

            self::assertStringContainsString('&lt;b&gt;Web page&lt;/b&gt;', $html);
            self::assertStringNotContainsString('<b>', $html);
        }
    }
}
