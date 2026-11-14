<?php

namespace {

}

namespace UAMS_Search\Tests {
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\TestCase;
    use stdClass;
    use UAMS_Search\Config;
    use UAMS_Search\Filters;

    final class FiltersTest extends TestCase
    {
        protected function setUp(): void
        {
            $GLOBALS['uams_test_options'] = [];
            $GLOBALS['uams_test_filters'] = [];
        }

        protected function tearDown(): void
        {
            $GLOBALS['uams_test_options'] = [];
            $GLOBALS['uams_test_filters'] = [];
        }

        /** @return array<string, array{mixed}> */
        public static function wrongTypes(): array
        {
            return ['null' => [null], 'false' => [false], 'int' => [7], 'string' => ['text'], 'array' => [['a']], 'object' => [new stdClass]];
        }

        #[DataProvider('wrongTypes')]
        public function test_a_string_filter_returning_another_type_falls_back_to_the_default(mixed $returned): void
        {
            if (is_string($returned)) {
                self::assertSame($returned, $this->string($returned));

                return;
            }

            self::assertSame('default', $this->string($returned));
        }

        #[DataProvider('wrongTypes')]
        public function test_an_array_filter_returning_another_type_falls_back_to_the_default(mixed $returned): void
        {
            $GLOBALS['uams_test_filters']['hook'] = [static fn (): mixed => $returned];
            $result = Filters::array('hook', ['default']);

            self::assertSame(is_array($returned) ? $returned : ['default'], $result);
        }

        public function test_the_arguments_after_the_default_reach_the_callback(): void
        {
            $GLOBALS['uams_test_filters']['hook'] = [static fn ($value, $first, $second): string => $value.$first.$second];

            self::assertSame('abc', Filters::string('hook', 'a', 'b', 'c'));
        }

        #[DataProvider('wrongTypes')]
        public function test_config_get_ignores_a_config_filter_that_returns_another_type(mixed $returned): void
        {
            $GLOBALS['uams_test_filters']['uams_search_config'] = [static fn (): mixed => $returned];

            $config = Config::get();

            self::assertIsArray($config);
            if (! is_array($returned)) {
                self::assertSame(Config::defaults(), $config);
            }
        }

        private function string(mixed $returned): string
        {
            $GLOBALS['uams_test_filters']['hook'] = [static fn (): mixed => $returned];

            return Filters::string('hook', 'default');
        }
    }
}
