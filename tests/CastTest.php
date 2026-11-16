<?php

namespace {

}

namespace UAMS_Search\Tests {
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\TestCase;
    use stdClass;
    use UAMS_Search\Cast;

    final class CastTest extends TestCase
    {
        /** @return array<string, array{mixed, int}> */
        public static function integers(): array
        {
            return [
                'int' => [7, 7],
                'negative int' => [-3, -3],
                'float truncates' => [2.9, 2],
                'numeric string' => ['42', 42],
                'padded numeric string' => [' 12', 12],
                'non-numeric string' => ['abc', 0],
                'true' => [true, 1],
                'false' => [false, 0],
                'null' => [null, 0],
                'array' => [[1, 2], 0],
                'empty array' => [[], 0],
                'object' => [new stdClass, 0],
            ];
        }

        #[DataProvider('integers')]
        public function test_int_reads_a_scalar_and_gives_zero_for_anything_else(mixed $value, int $expected): void
        {
            self::assertSame($expected, Cast::int($value));
        }

        /** @return array<string, array{mixed, float}> */
        public static function floats(): array
        {
            return [
                'int' => [7, 7.0],
                'float' => [2.5, 2.5],
                'numeric string' => ['2.5', 2.5],
                'non-numeric string' => ['abc', 0.0],
                'true' => [true, 1.0],
                'null' => [null, 0.0],
                'array' => [[1.5], 0.0],
                'object' => [new stdClass, 0.0],
            ];
        }

        #[DataProvider('floats')]
        public function test_float_reads_a_scalar_and_gives_zero_for_anything_else(mixed $value, float $expected): void
        {
            self::assertSame($expected, Cast::float($value));
        }

        /** @return array<string, array{mixed, string}> */
        public static function strings(): array
        {
            return [
                'string' => ['text', 'text'],
                'empty string' => ['', ''],
                'int' => [7, '7'],
                'float' => [2.5, '2.5'],
                'true' => [true, '1'],
                'false' => [false, ''],
                'null' => [null, ''],
                'array' => [['a'], ''],
                'object' => [new stdClass, ''],
                'stringable' => [new class
                {
                    public function __toString(): string
                    {
                        return 'named';
                    }
                }, 'named'],
            ];
        }

        #[DataProvider('strings')]
        public function test_string_reads_a_scalar_or_stringable_and_gives_an_empty_string_for_anything_else(mixed $value, string $expected): void
        {
            self::assertSame($expected, Cast::string($value));
        }
    }
}
