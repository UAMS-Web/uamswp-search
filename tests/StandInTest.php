<?php

declare(strict_types=1);

namespace UAMS_Search\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionClassConstant;
use ReflectionMethod;
use ReflectionNamedType;
use stdClass;
use UAMS_Search\Tests\Support\StandIn;
use UnexpectedValueException;

/**
 * The typed accessor for the stand-in state the unit tests keep in `$GLOBALS`.
 *
 * The accessor is covered here on three sides: every setter and getter pair round-trips and answers a default while its key is absent, the container checks name the key they refuse, and a scan of the unit tests and preview scripts shows no stand-in key they use is missing from the accessor.
 */
final class StandInTest extends TestCase
{
    /** @var array<string, mixed> The keys the accessor owns as they stood before the test, put back after it. */
    private array $saved = [];

    protected function setUp(): void
    {
        foreach (self::keys() as $key) {
            if (array_key_exists($key, $GLOBALS)) {
                $this->saved[$key] = $GLOBALS[$key];
            }
        }

        StandIn::forget_all();
    }

    protected function tearDown(): void
    {
        StandIn::forget_all();
        foreach ($this->saved as $key => $value) {
            $GLOBALS[$key] = $value;
        }
    }

    /** @return list<string> */
    private static function keys(): array
    {
        $constant = (new ReflectionClass(StandIn::class))->getReflectionConstant('KEYS');
        self::assertInstanceOf(ReflectionClassConstant::class, $constant);
        $keys = $constant->getValue();
        self::assertIsArray($keys);

        /** @var list<string> $keys */
        return $keys;
    }

    /**
     * The keys a source text reads from the globals array as string literals.
     *
     * @return list<string>
     */
    private static function literals(string $source): array
    {
        preg_match_all('/\$GLOBALS\[\'([A-Za-z_0-9]+)\'\]/', $source, $found);

        return array_values(array_unique($found[1]));
    }

    /**
     * The one-parameter setters, each with the getter that reads the same key back.
     *
     * @return array<string, array{string, string}>
     */
    public static function pairs(): array
    {
        $pairs = [];
        foreach ((new ReflectionClass(StandIn::class))->getMethods(ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_STATIC) as $method) {
            $getter = substr($method->getName(), 4);
            if (! str_starts_with($method->getName(), 'set_') || $method->getNumberOfParameters() !== 1) {
                continue;
            }

            if (! method_exists(StandIn::class, $getter) || (new ReflectionMethod(StandIn::class, $getter))->getNumberOfParameters() !== 0) {
                continue;
            }

            $pairs[$getter] = [$method->getName(), $getter];
        }

        return $pairs;
    }

    /** A value of the type the setter takes, different from what the getter answers while the key is absent. */
    private static function sample(string $setter, mixed $absent): mixed
    {
        $type = (new ReflectionMethod(StandIn::class, $setter))->getParameters()[0]->getType();
        $name = $type instanceof ReflectionNamedType ? $type->getName() : 'union';

        return match ($name) {
            'bool' => ! $absent,
            'string' => 'sample',
            'int' => 7,
            'array' => ['sample'],
            'object' => new stdClass,
            default => 5,
        };
    }

    public function test_every_setter_has_a_getter_and_the_pairs_are_found(): void
    {
        $pairs = self::pairs();

        // The scan is not a pass over nothing: the accessor has dozens of pairs, including these known ones.
        self::assertGreaterThan(40, count($pairs));
        foreach (['options', 'hooks', 'caps', 'multisite', 'environment', 'redirect', 'site_ids', 'preview_results', 'menu'] as $known) {
            self::assertArrayHasKey($known, $pairs);
        }
    }

    #[DataProvider('pairs')]
    public function test_a_value_set_is_the_value_read_back(string $setter, string $getter): void
    {
        $absent = StandIn::{$getter}();
        $value = self::sample($setter, $absent);

        StandIn::{$setter}($value);

        self::assertEquals($value, StandIn::{$getter}());
        self::assertNotEquals($absent, StandIn::{$getter}(), 'The sample differs from the answer while the key is absent, so the read-back is not the default.');
    }

    public function test_an_absent_key_answers_its_documented_default(): void
    {
        self::assertFalse(StandIn::multisite());
        self::assertFalse(StandIn::logged_in());
        self::assertFalse(StandIn::subdomain());
        self::assertFalse(StandIn::network_admin());
        self::assertFalse(StandIn::acf_enabled());
        self::assertFalse(StandIn::meta_record());
        self::assertTrue(StandIn::main_site());
        self::assertSame('production', StandIn::environment());
        self::assertSame('UTC', StandIn::timezone());
        self::assertSame('', StandIn::pagenow());
        self::assertNull(StandIn::attached());
        self::assertNull(StandIn::uploads());
        self::assertNull(StandIn::redirect());
        self::assertNull(StandIn::blocks());
        self::assertNull(StandIn::blog_calls());
        self::assertNull(StandIn::blog_names());
        self::assertNull(StandIn::site_ids());
        self::assertNull(StandIn::sites_return());
        self::assertSame(['publish'], StandIn::viewable_statuses());
        self::assertSame(-1, StandIn::memory_bytes());
        self::assertSame([], StandIn::options());
        self::assertSame([], StandIn::caps());
        self::assertFalse(StandIn::has_posts_default());
        self::assertFalse(StandIn::has_get_term());
        self::assertFalse(StandIn::has_db());
        self::assertSame('fallback', StandIn::option('missing', 'fallback'));
        self::assertFalse(StandIn::acf_field('missing'));
        self::assertFalse(StandIn::cron_next('missing'));
        self::assertSame([], StandIn::filters_for('missing'));
        self::assertSame('', StandIn::meta_value(1, 'missing'));
        self::assertSame('', StandIn::term_meta_value(1, 'missing'));
    }

    public function test_every_getter_answers_while_its_key_is_absent(): void
    {
        $answered = 0;
        foreach (self::pairs() as [, $getter]) {
            StandIn::{$getter}();
            $answered++;
        }

        self::assertSame(count(self::pairs()), $answered);
    }

    public function test_adding_and_putting_extend_what_is_stored(): void
    {
        StandIn::add_capability('manage_options');
        StandIn::add_capability('edit_posts');
        self::assertSame(['manage_options', 'edit_posts'], StandIn::caps());

        StandIn::add_blog_call(['switch', 2]);
        StandIn::add_blog_call(['restore']);
        self::assertSame([['switch', 2], ['restore']], StandIn::blog_calls());

        StandIn::put_option('alpha', 1);
        StandIn::put_option('beta', null);
        self::assertSame(['alpha' => 1, 'beta' => null], StandIn::options());
        self::assertSame(1, StandIn::option('alpha'));
        self::assertSame('as absent', StandIn::option('beta', 'as absent'), 'A stored null reads as absent, as the get_option() stand-in answers it.');

        $first = static fn (): string => 'first';
        $second = static fn (): string => 'second';
        StandIn::add_filter('name', $first);
        StandIn::add_filter('name', $second);
        self::assertSame([$first, $second], StandIn::filters_for('name'));
        StandIn::add_hook('init', $first);
        self::assertSame(['init' => [$first]], StandIn::hooks());
        StandIn::add_hook_args('init', [$first, 10, 1]);
        self::assertSame(['init' => [[$first, 10, 1]]], StandIn::hook_args());

        StandIn::put_meta(3, 'title', 'One');
        StandIn::put_meta(3, 'subtitle', 'Two');
        self::assertSame([3 => ['title' => 'One', 'subtitle' => 'Two']], StandIn::meta());
        self::assertSame('Two', StandIn::meta_value(3, 'subtitle'));
        StandIn::put_term_meta(9, 'title', 'term value');
        self::assertSame('term value', StandIn::term_meta_value(9, 'title'));

        StandIn::put_cron('uams_search_queue', 123);
        self::assertSame(123, StandIn::cron_next('uams_search_queue'));
        StandIn::put_acf_field('subtitle', ['name' => 'subtitle']);
        self::assertSame(['name' => 'subtitle'], StandIn::acf_field('subtitle'));
    }

    public function test_a_null_post_is_an_answer_and_an_unset_default_is_not(): void
    {
        StandIn::set_posts_default(null);
        self::assertTrue(StandIn::has_posts_default());
        self::assertNull(StandIn::posts_default());

        StandIn::set_get_term(false);
        self::assertTrue(StandIn::has_get_term());
        self::assertFalse(StandIn::get_term());

        StandIn::put_post(7, null);
        self::assertSame([7 => null], StandIn::posts());
    }

    public function test_a_memory_list_is_answered_in_order_and_a_number_is_answered_each_time(): void
    {
        StandIn::set_memory_bytes([100, 200]);
        self::assertSame(100, StandIn::next_memory_bytes());
        self::assertSame([200], StandIn::memory_bytes());
        self::assertSame(200, StandIn::next_memory_bytes());
        self::assertSame(-1, StandIn::next_memory_bytes(), 'An exhausted list reads as no limit.');

        StandIn::set_memory_bytes(512);
        $answers = [StandIn::next_memory_bytes(), StandIn::next_memory_bytes()];
        self::assertSame([512, 512], $answers);
    }

    public function test_the_database_and_query_doubles_are_returned_as_their_class(): void
    {
        $double = new class extends stdClass
        {
            /** @var list<string> */
            public array $queries = ['SELECT 1'];
        };
        StandIn::set_db($double);
        StandIn::set_wp_query($double);

        self::assertTrue(StandIn::has_db());
        self::assertSame(['SELECT 1'], StandIn::db($double::class)->queries);
        self::assertSame($double, StandIn::wp_query(stdClass::class));
    }

    public function test_a_double_of_another_class_throws_naming_the_key_and_the_class(): void
    {
        StandIn::set_db(new stdClass);

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('Stand-in state "wpdb" holds stdClass, expected an instance of ArrayObject.');
        StandIn::db(\ArrayObject::class);
    }

    #[DataProvider('wrongTypes')]
    public function test_a_key_holding_the_wrong_type_throws_naming_the_key(string $key, mixed $stored, string $getter, string $message): void
    {
        $GLOBALS[$key] = $stored;

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage($message);
        StandIn::{$getter}();
    }

    /** @return array<string, array{string, mixed, string, string}> */
    public static function wrongTypes(): array
    {
        return [
            'a list key holding a string' => ['uams_test_caps', 'manage_options', 'caps', 'Stand-in state "uams_test_caps" holds string, expected an array.'],
            'a nullable list key holding an int' => ['uams_test_blocks', 3, 'blocks', 'Stand-in state "uams_test_blocks" holds int, expected an array or null.'],
            'a flag holding a string' => ['uams_test_multisite', 'yes', 'multisite', 'Stand-in state "uams_test_multisite" holds string, expected a bool.'],
            'a name holding an int' => ['uams_test_environment', 4, 'environment', 'Stand-in state "uams_test_environment" holds int, expected a string.'],
            'a nullable name holding an array' => ['uams_test_redirect', [], 'redirect', 'Stand-in state "uams_test_redirect" holds array, expected a string or null.'],
            'a post double holding a string' => ['uams_test_posts_default', 'post', 'posts_default', 'Stand-in state "uams_test_posts_default" holds string, expected an object or null.'],
            'a memory limit holding a string' => ['uams_test_memory_bytes', '256M', 'memory_bytes', 'Stand-in state "uams_test_memory_bytes" holds string, expected an int or a list of ints.'],
        ];
    }

    public function test_forget_all_removes_every_key_the_accessor_owns(): void
    {
        foreach (self::keys() as $key) {
            $GLOBALS[$key] = 'set';
        }

        StandIn::forget_all();

        foreach (self::keys() as $key) {
            self::assertArrayNotHasKey($key, $GLOBALS);
        }
    }

    public function test_the_key_list_names_exactly_the_keys_the_accessor_source_uses(): void
    {
        $source = (string) file_get_contents(__DIR__.'/Support/StandIn.php');
        $used = self::literals($source);
        $keys = self::keys();
        sort($used);
        sort($keys);

        self::assertGreaterThan(60, count($used));
        self::assertSame($keys, $used);
    }

    public function test_the_scan_finds_a_key_in_a_source_text_and_not_in_one_without(): void
    {
        self::assertSame(['uams_test_caps'], self::literals("return \$GLOBALS['uams_test_caps'] ?? [];"));
        self::assertSame([], self::literals('return $caps ?? [];'));
    }

    public function test_every_stand_in_key_the_unit_tests_and_preview_scripts_use_has_an_accessor(): void
    {
        $files = array_merge(array_filter(glob(__DIR__.'/*Test.php') ?: [], static fn (string $file): bool => basename($file) !== basename(__FILE__)), glob(__DIR__.'/render-*.php') ?: [], [__DIR__.'/bootstrap.php', __DIR__.'/Support/wp-shims.php', __DIR__.'/Support/meta-record.php']);

        $used = [];
        foreach ($files as $file) {
            foreach (self::literals((string) file_get_contents($file)) as $key) {
                $used[$key][] = basename($file);
            }
        }

        // Denominator before the verdict: the scan read many files and found many keys, so an empty miss list means something.
        self::assertGreaterThan(30, count($files));
        self::assertGreaterThan(60, count($used));

        // This file is left out of the scan, because its own fixtures name keys inside strings; no other file is exempt.
        $missing = array_diff(array_keys($used), self::keys());
        sort($missing);

        self::assertSame([], $missing, 'A stand-in key with no accessor: add a getter and setter for it to StandIn.');
    }
}
