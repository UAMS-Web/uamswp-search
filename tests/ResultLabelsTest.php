<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use UAMS_Search\Integration;

if (! function_exists('size_format')) {
    function size_format($size, $decimals = 0)
    {
        return round($size / 1024).' KB';
    }
}

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
            'provider' => ['provider', 'Person'],
            'location' => ['location', 'Clinical Location'],
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
