<?php

namespace UAMS_Search\Tests {
    use InvalidArgumentException;
    use PHPUnit\Framework\TestCase;
    use UAMS_Search\Tests\Support\Prepare;

    /** Pins for the shared `prepare()` stand-in (#336). */
    final class PrepareTest extends TestCase
    {
        public function test_an_integer_placeholder_reads_the_argument_as_an_integer(): void
        {
            self::assertSame('WHERE id=7', Prepare::sql('WHERE id=%d', ['7']));
            self::assertSame('WHERE id=0', Prepare::sql('WHERE id=%d', ['seven']));
        }

        public function test_a_string_placeholder_is_quoted_and_escaped(): void
        {
            self::assertSame("WHERE name='Ann'", Prepare::sql('WHERE name=%s', ['Ann']));
            self::assertSame("WHERE name='O\\'Neil'", Prepare::sql('WHERE name=%s', ["O'Neil"]));
        }

        public function test_a_float_placeholder_is_written_without_a_locale(): void
        {
            self::assertSame('WHERE score>1.500000', Prepare::sql('WHERE score>%f', [1.5]));
            self::assertSame('WHERE score>2.000000', Prepare::sql('WHERE score>%F', ['2']));
        }

        public function test_an_identifier_placeholder_is_wrapped_in_backticks(): void
        {
            self::assertSame('SELECT 1 FROM `wp_posts`', Prepare::sql('SELECT 1 FROM %i', ['wp_posts']));
        }

        public function test_a_backtick_inside_an_identifier_is_doubled(): void
        {
            self::assertSame('SELECT 1 FROM `we``ird`', Prepare::sql('SELECT 1 FROM %i', ['we`ird']));
        }

        public function test_a_literal_percent_sign_is_kept(): void
        {
            self::assertSame("WHERE name LIKE 'a%' AND id=3", Prepare::sql("WHERE name LIKE 'a%%' AND id=%d", [3]));
        }

        public function test_placeholders_fill_in_order_across_types(): void
        {
            self::assertSame("SELECT 1 FROM `t` WHERE a=1 AND b='x' AND c IN ('p','q')", Prepare::sql('SELECT 1 FROM %i WHERE a=%d AND b=%s AND c IN (%s,%s)', ['t', 1, 'x', 'p', 'q']));
        }

        public function test_one_array_argument_is_read_as_the_list(): void
        {
            self::assertSame("SELECT 1 FROM `t` WHERE a=1 AND b='x'", Prepare::sql('SELECT 1 FROM %i WHERE a=%d AND b=%s', [['t', 1, 'x']]));
        }

        public function test_a_query_with_no_placeholders_and_no_arguments_is_unchanged(): void
        {
            self::assertSame('SELECT 1', Prepare::sql('SELECT 1', []));
        }

        public function test_fewer_arguments_than_placeholders_is_refused(): void
        {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('more placeholders than arguments: %s');

            Prepare::sql('a=%d AND b=%s', [1]);
        }

        public function test_more_arguments_than_placeholders_is_refused(): void
        {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('1 placeholders and 2 arguments');

            Prepare::sql('a=%d', [1, 2]);
        }
    }
}
