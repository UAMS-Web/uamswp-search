<?php

namespace UAMS_Search\Tests {
    use PHPUnit\Framework\TestCase;
    use RuntimeException;
    use UAMS_Search\Database;

    final class DatabaseTest extends TestCase
    {
        protected function tearDown(): void
        {
            unset($GLOBALS['wpdb']);
        }

        /** @return object A stand-in with the prefix and the site switch of the real object. */
        private static function double(): object
        {
            return new class
            {
                public string $prefix = 'wp_';

                public function set_blog_id(int $blog_id): int
                {
                    $this->prefix = $blog_id === 1 ? 'wp_' : 'wp_'.$blog_id.'_';

                    return $blog_id;
                }
            };
        }

        public function test_returns_the_global_object_on_a_single_site(): void
        {
            $db = self::double();
            $GLOBALS['wpdb'] = $db;

            self::assertSame($db, Database::get());
            self::assertSame('wp_', Database::get()->prefix);
        }

        public function test_returns_the_same_object_with_the_prefix_of_a_switched_site(): void
        {
            $db = self::double();
            $GLOBALS['wpdb'] = $db;
            $before = Database::get();

            // WordPress switches the site by changing the prefix on the one object
            $before->set_blog_id(3);

            $after = Database::get();

            self::assertSame($before, $after);
            self::assertSame('wp_3_', $after->prefix);

            $after->set_blog_id(1);
            self::assertSame('wp_', Database::get()->prefix);
        }

        public function test_stops_with_a_message_when_there_is_no_object(): void
        {
            unset($GLOBALS['wpdb']);

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('The WordPress database object is not available');

            Database::get();
        }

        public function test_stops_when_the_global_is_not_an_object(): void
        {
            $GLOBALS['wpdb'] = 'not an object';

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('The WordPress database object is not available');

            Database::get();
        }
    }
}
