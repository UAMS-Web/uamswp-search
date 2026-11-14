<?php

declare(strict_types=1);

namespace UAMS_Search\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Every unit test class sits in a named namespace.
 *
 * `pest --mutate` finds the tests that cover a line from the ids in its coverage report, and it reads an id only when a backslash precedes the class name. A class in the global namespace is never matched, so every mutant in the code it covers is reported `UNCOVERED` with `0 tested` even though the suite passes (#200).
 */
final class TestClassNamespaceTest extends TestCase
{
    /** Whether a test file declares its classes in a named namespace, in either the statement or the bracketed form. */
    private static function namespaced(string $source): bool
    {
        $named = false;
        $tokens = token_get_all($source);
        foreach ($tokens as $index => $token) {
            if (! \is_array($token) || $token[0] !== T_NAMESPACE) {
                continue;
            }

            // A namespace keyword followed by a name declares a named namespace; one followed by a brace is the global block.
            for ($next = $index + 1; isset($tokens[$next]); $next++) {
                $part = $tokens[$next];
                if (\is_array($part) && \in_array($part[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }

                $named = $named || (\is_array($part) && \in_array($part[0], [T_STRING, T_NAME_QUALIFIED], true));
                break;
            }
        }

        return $named;
    }

    public function test_the_check_tells_a_namespaced_file_from_a_global_one(): void
    {
        self::assertTrue(self::namespaced("<?php\nnamespace UAMS_Search\\Tests;\nclass A {}\n"));
        self::assertTrue(self::namespaced("<?php\nnamespace {\n}\nnamespace UAMS_Search\\Tests {\nclass A {}\n}\n"));
        self::assertFalse(self::namespaced("<?php\nclass A {}\n"));
        self::assertFalse(self::namespaced("<?php\nnamespace {\nclass A {}\n}\n"));
        self::assertFalse(self::namespaced("<?php\n// namespace UAMS_Search\\Tests;\nclass A {}\n"));
    }

    public function test_every_unit_test_file_declares_its_class_in_a_named_namespace(): void
    {
        $files = glob(__DIR__.'/*Test.php');
        self::assertIsArray($files);
        self::assertGreaterThan(20, \count($files), 'The sweep read the unit test files');

        $global = [];
        foreach ($files as $file) {
            if (! self::namespaced((string) file_get_contents($file))) {
                $global[] = basename($file);
            }
        }

        self::assertSame([], $global, 'Test files with no named namespace are invisible to the mutation run');
    }
}
