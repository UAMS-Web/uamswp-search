<?php

namespace {

}

namespace UAMS_Search\Tests {
    use PHPUnit\Framework\TestCase;
    use ReflectionMethod;
    use UAMS_Search\Config;
    use UAMS_Search\Integration;
    use UAMS_Search\Tests\Support\StandIn;

    final class TypeLabelsTest extends TestCase
    {
        protected function setUp(): void
        {
            $GLOBALS['uams_test_filters'] = [];
            $GLOBALS['uams_test_options'] = ['uams_search_settings' => ['types' => ['provider' => 1.5, 'expertise' => 1.3, 'location' => 1.2, 'condition' => 1.0, 'post' => 0.8, 'attachment' => 0.8], 'pdf' => true]];
            $GLOBALS['uams_test_types'] = [];
            foreach (['provider' => 'Providers', 'expertise' => 'Expertise', 'location' => 'Locations', 'condition' => 'Conditions', 'post' => 'Posts', 'attachment' => 'Media'] as $type => $name) {
                $GLOBALS['uams_test_types'][$type] = (object) ['labels' => (object) ['name' => $name]];
            }

            $_GET = [];
        }

        protected function tearDown(): void
        {
            $GLOBALS['uams_test_filters'] = [];
            $GLOBALS['uams_test_options'] = [];
            $GLOBALS['uams_test_types'] = [];
        }

        /** @return list<string> */
        private function names(): array
        {
            ob_start();
            try {
                (new ReflectionMethod(Integration::class, 'filters'))->invoke(null, false);
            } finally {
                $html = (string) ob_get_clean();
            }

            $nav = substr($html, (int) strpos($html, 'aria-label="Result type"'));
            preg_match_all('/<a [^>]*>([^<]*)<\/a>/', $nav, $matches);

            return $matches[1];
        }

        public function test_the_filters_use_the_statamic_names_in_its_order_and_keep_the_rest(): void
        {
            self::assertSame(['All results', 'People', 'Clinical Areas of Expertise', 'Clinical Locations', 'Conditions', 'News', 'PDFs'], $this->names());
        }

        public function test_a_type_with_no_counterpart_keeps_its_wordpress_name(): void
        {
            self::assertArrayNotHasKey('condition', Config::type_labels());
            self::assertContains('Conditions', $this->names());
        }

        public function test_condition_and_treatment_stay_separate_types_and_never_become_clinical_resources(): void
        {
            $GLOBALS['uams_test_options'] = ['uams_search_settings' => ['types' => ['provider' => 1.5, 'expertise' => 1.3, 'location' => 1.2, 'condition' => 1.0, 'treatment' => 1.0, 'post' => 0.8, 'attachment' => 0.8], 'pdf' => true]];
            $GLOBALS['uams_test_types'] = [
                'condition' => (object) ['labels' => (object) ['name' => 'Conditions']],
                'treatment' => (object) ['labels' => (object) ['name' => 'Treatments']],
            ];

            $names = $this->names();

            self::assertSame(1, array_count_values($names)['Conditions']);
            self::assertSame(1, array_count_values($names)['Treatments']);
            self::assertNotContains('Clinical Resources', $names);
            self::assertArrayNotHasKey('condition', Config::type_labels());
            self::assertArrayNotHasKey('treatment', Config::type_labels());
            self::assertNotContains('Clinical Resources', Config::type_labels());
            self::assertSame('Condition', Config::result_labels()['condition']);
            self::assertSame('Treatment or Procedure', Config::result_labels()['treatment']);
            self::assertNotContains('Clinical Resources', Config::result_labels());
        }

        public function test_a_filter_can_rename_a_type(): void
        {
            StandIn::add_filter('uams_search_type_labels', static fn (array $labels): array => ['provider' => 'Doctors'] + $labels);

            self::assertSame(['All results', 'Doctors'], array_slice($this->names(), 0, 2));
        }

        public function test_a_filter_that_returns_a_number_for_a_label_gets_text_back(): void
        {
            StandIn::add_filter('uams_search_type_labels', static fn (array $labels): array => ['provider' => 42] + $labels);

            self::assertSame('42', Config::type_labels()['provider']);
        }
    }
}
