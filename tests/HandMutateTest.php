<?php

declare(strict_types=1);

namespace UAMS_Search\Tests;

use PHPUnit\Framework\TestCase;

use function UAMS_Search\HandMutation\killControl;
use function UAMS_Search\HandMutation\lints;
use function UAMS_Search\HandMutation\mutants;
use function UAMS_Search\HandMutation\report;
use function UAMS_Search\HandMutation\runSuite;
use function UAMS_Search\HandMutation\shard;

require_once __DIR__.'/../scripts/hand-mutate.php';

/**
 * The hand-mutation runner (scripts/hand-mutate.php), which scores the integration suite on Linux (#208).
 *
 * The run itself needs WordPress and a database, so it is not exercised here. These tests pin what decides whether its record can be trusted:
 * which mutants it generates, that none equals the source, and that its controls and exit handling behave.
 */
final class HandMutateTest extends TestCase
{
    private const SOURCE = "<?php\n\ndeclare(strict_types=1);\n\nfunction f(int \$a, bool \$b): int\n{\n    // if (\$a === 5) is only a comment\n    if (\$a === 5 && ! \$b) {\n        return \$a + 2;\n    }\n\n    \$ok = true;\n    \$c = \$a < 3 ? -1 : 0;\n\n    return \$c;\n}\n";

    /** @return array<string, list<array<string, mixed>>> The generated mutants, keyed by operator. */
    private function byOperator(): array
    {
        $out = [];
        foreach (mutants(self::SOURCE) as $mutant) {
            $out[$mutant['operator']][] = $mutant;
        }

        return $out;
    }

    public function test_each_operator_changes_exactly_the_token_it_names(): void
    {
        $by = $this->byOperator();

        self::assertEqualsCanonicalizing(['IdenticalToNotIdentical', 'BooleanAndToBooleanOr', 'RemoveNot', 'PlusToMinus', 'TrueToFalse', 'LessToLessOrEqual', 'IncrementInteger', 'DecrementInteger'], array_keys($by));
        self::assertStringContainsString('if ($a !== 5 && ! $b)', $by['IdenticalToNotIdentical'][0]['source']);
        self::assertStringContainsString('if ($a === 5 || ! $b)', $by['BooleanAndToBooleanOr'][0]['source']);
        self::assertStringContainsString('if ($a === 5 &&  $b)', $by['RemoveNot'][0]['source']);
        self::assertStringContainsString('return $a - 2;', $by['PlusToMinus'][0]['source']);
        self::assertStringContainsString('$ok = false;', $by['TrueToFalse'][0]['source']);
        self::assertStringContainsString('$c = $a <= 3 ?', $by['LessToLessOrEqual'][0]['source']);
        self::assertSame(['IdenticalToNotIdentical', '===', '!==', 8, 'if ($a === 5 && ! $b) {'], [$by['IdenticalToNotIdentical'][0]['operator'], $by['IdenticalToNotIdentical'][0]['from'], $by['IdenticalToNotIdentical'][0]['to'], $by['IdenticalToNotIdentical'][0]['line'], $by['IdenticalToNotIdentical'][0]['line_text']]);
    }

    public function test_every_mutant_differs_from_the_source_and_still_parses(): void
    {
        $all = mutants(self::SOURCE);
        self::assertCount(15, $all);

        foreach ($all as $mutant) {
            self::assertNotSame(self::SOURCE, $mutant['source'], $mutant['operator']);
            $path = tempnam(sys_get_temp_dir(), 'hm');
            file_put_contents($path, $mutant['source']);
            self::assertTrue(lints($path), $mutant['operator'].' left a file that does not parse.');
            unlink($path);
        }
    }

    public function test_comments_the_strict_types_declaration_and_signs_are_not_mutated(): void
    {
        // The comment holds `===` and `5`; the declaration holds `1`; `-1` holds a sign.
        $operators = array_map(static fn (array $mutant): string => $mutant['operator'].':'.$mutant['line'], mutants(self::SOURCE));

        self::assertNotContains('IdenticalToNotIdentical:7', $operators);
        self::assertNotContains('IncrementInteger:3', $operators);
        self::assertNotContains('MinusToPlus:13', $operators);
        self::assertContains('IdenticalToNotIdentical:8', $operators);
    }

    public function test_a_called_or_namespaced_true_is_not_a_boolean_literal(): void
    {
        $source = "<?php\n\n\$x = \\true_value(\$y);\n\$z = Foo::false();\n\$w = \$o->true;\n";

        self::assertSame([], mutants($source));
    }

    public function test_the_kill_control_throws_as_soon_as_the_file_loads(): void
    {
        $planted = killControl(self::SOURCE);

        self::assertStringContainsString("declare(strict_types=1);\nthrow new \\RuntimeException('hand-mutation control');\n", $planted);
        self::assertNotSame(self::SOURCE, $planted);

        // The throw follows `declare` and `namespace`, which PHP requires first, so the planted file compiles.
        $namespaced = killControl("<?php

declare(strict_types=1);

namespace Demo\Part;

use Foo\Bar;

function g() {}
");
        self::assertStringContainsString("namespace Demo\Part;
throw new \RuntimeException('hand-mutation control');


use Foo\Bar;", $namespaced);
        $path = tempnam(sys_get_temp_dir(), 'hm');
        file_put_contents($path, $namespaced);
        self::assertTrue(lints($path));
        unlink($path);

        $withoutDeclare = killControl("<?php\n\nfunction g() {}\n");
        self::assertStringStartsWith("<?php\nthrow new \\RuntimeException('hand-mutation control');", $withoutDeclare);

        $this->expectException(\LogicException::class);
        killControl('no php here');
    }

    public function test_a_run_reports_the_exit_code_the_failing_test_and_a_timeout(): void
    {
        $passed = runSuite('php -r "exit(0);"', 30);
        self::assertSame(0, $passed['code']);
        self::assertFalse($passed['timed_out']);

        $failed = runSuite('php -r "echo \"There was 1 failure:\n\n1) Foo\\Bar::test_it\nboom\n\"; exit(1);"', 30);
        self::assertSame(1, $failed['code']);
        self::assertSame('Foo\\Bar::test_it', $failed['killer']);

        $fatal = runSuite('php -r "echo \"PHP Fatal error: Uncaught RuntimeException: hand-mutation control\n\"; exit(255);"', 30);
        self::assertSame(255, $fatal['code']);
        self::assertSame('PHP Fatal error: Uncaught RuntimeException: hand-mutation control', $fatal['killer']);

        $stopped = runSuite('php -r "sleep(20);"', 1);
        self::assertTrue($stopped['timed_out']);
        self::assertNotSame(0, $stopped['code']);
        self::assertLessThan(10, $stopped['seconds']);
    }

    public function test_shards_partition_the_mutants_with_none_dropped_or_repeated(): void
    {
        $items = range(1, 10);

        self::assertSame([1, 4, 7, 10], shard($items, 1, 3));
        self::assertSame([2, 5, 8], shard($items, 2, 3));
        self::assertSame([3, 6, 9], shard($items, 3, 3));
        self::assertSame($items, shard($items, 1, 1));
    }

    public function test_the_record_names_each_unkilled_mutant_and_the_controls(): void
    {
        $rows = [
            ['operator' => 'RemoveNot', 'line' => 8, 'from' => '!', 'to' => '', 'killer' => '', 'reason' => '', 'result' => 'survived'],
            ['operator' => 'PlusToMinus', 'line' => 9, 'from' => '+', 'to' => '-', 'killer' => '', 'reason' => 'The sum is only logged.', 'result' => 'equivalent'],
            ['operator' => 'TrueToFalse', 'line' => 12, 'from' => 'true', 'to' => 'false', 'killer' => 'Foo::test_a|b', 'reason' => '', 'result' => 'killed'],
        ];
        $md = report(
            ['Demo' => ['total' => 3, 'killed' => 1, 'equivalent' => 1, 'survived' => 1, 'invalid' => 0, 'rows' => $rows]],
            ['Planted survivor' => ['result' => 'survived', 'killer' => ''], 'Planted kill (throws on load)' => ['result' => 'killed', 'killer' => 'Foo::test_a']],
            'PHP test on Linux.'
        );

        self::assertStringContainsString('| Planted survivor | survived | survived | n/a |', $md);
        self::assertStringContainsString('| Planted kill (throws on load) | killed | killed | `Foo::test_a` |', $md);
        self::assertStringContainsString('| `Demo` | 3 | 1 | 1 | 1 | 0 |', $md);
        self::assertStringContainsString('| RemoveNot | 8 | `!` to `` | survived |', $md);
        self::assertStringContainsString('| PlusToMinus | 9 | `+` to `-` | equivalent: The sum is only logged. |', $md);
        self::assertStringContainsString('| `Demo` | TrueToFalse | 12 | `Foo::test_a\\|b` |', $md);
        self::assertStringNotContainsString('| TrueToFalse | 12 | `true` to `false` |', $md);
    }
}
