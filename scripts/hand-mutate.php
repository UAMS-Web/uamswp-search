<?php

/**
 * Hand-mutation run for the classes the WordPress integration suite covers.
 *
 * The shared mutation job scores the Pest unit suite only, so the classes that the PHPUnit integration suite
 * exercises are scored here instead: each mutant is applied to the source file, the integration suite runs
 * against it, and the mutant is recorded as killed (the suite failed or timed out) or survived (the suite
 * passed). The file is copied aside first and put back after every mutant, whatever the result.
 *
 * Usage, from the repository root, with the integration suite installed and a test database running (the
 * `integration` job in .github/workflows/ci.yml shows the environment):
 *
 *   php scripts/hand-mutate.php --class=Rebuild,Indexer [--shard=1/4] [--report=out.md] [--json=out.json]
 *   php scripts/hand-mutate.php --class=Rebuild --list
 *
 * Options:
 *   --class=A,B     Classes to mutate (`all` for every class named in CLASSES). Required.
 *   --list          Print the mutants and exit; runs nothing.
 *   --shard=I/N     Run every Nth mutant, starting at the Ith (1-based), so one class can be split across jobs.
 *   --phpunit=CMD   The command that runs the suite (default below). A non-zero exit means the mutant was killed.
 *   --unit=CMD      The command for the Pest unit suite, run on a mutant the integration suite did not kill (default below);
 *                   `--unit=none` skips it. A mutant that suite kills is recorded as killed by the unit suite.
 *   --timeout=SEC   Seconds before a mutant's run is stopped and counted as killed (default: four times the
 *                   baseline run, at least 60).
 *   --report=PATH   Write the Markdown record here (also appended to GITHUB_STEP_SUMMARY when it is set).
 *   --json=PATH     Write the same record as JSON.
 *   --fail-on-survivor  Exit 1 when a survivor is not listed in tests/Integration/hand-mutation-equivalents.json.
 *
 * Exit codes: 0 finished, 1 unexplained survivors under --fail-on-survivor, 2 the unmutated suite failed or the
 * options are wrong, 3 a control behaved wrongly (the run could not have failed, or could not have passed).
 *
 * Equivalent mutants are recorded in tests/Integration/hand-mutation-equivalents.json as objects with `class`,
 * `operator`, `line_text` (the source line, trimmed) and `reason`.
 */

declare(strict_types=1);

namespace UAMS_Search\HandMutation;

const CLASSES = [
    'Admin' => 'includes/Admin.php',
    'CardFields' => 'includes/CardFields.php',
    'CLI' => 'includes/CLI.php',
    'Indexer' => 'includes/Indexer.php',
    'Integration' => 'includes/Integration.php',
    'LocationCard' => 'includes/LocationCard.php',
    'Plugin' => 'includes/Plugin.php',
    'PluginFile' => 'uams-search.php',
    'ProviderCard' => 'includes/ProviderCard.php',
    'Rebuild' => 'includes/Rebuild.php',
];

/** The Pest unit suite, run against a mutant the integration suite did not kill: most of these classes are also covered there. */
const UNIT_COMMAND = 'php -d pcov.enabled=0 vendor/bin/pest --bail --colors=never';

const DEFAULT_COMMAND = 'php -d pcov.enabled=0 tests/Integration/vendor/bin/phpunit --configuration tests/Integration/phpunit.xml --stop-on-failure --colors=never';

const EQUIVALENTS_FILE = 'tests/Integration/hand-mutation-equivalents.json';

/** The line a finished PHPUnit run prints on success, and the line a finished Pest run prints. */
const PHPUNIT_FINISHED = '/^OK[ ,]/m';

const PEST_FINISHED = '/^\s*Tests:.*\bpassed\b/m';

const SURVIVE_CONTROL = "\n// hand-mutation control: a comment changes no behavior\n";

/**
 * Every mutant of one source text, in source order.
 *
 * Each mutant is `['operator' => string, 'line' => int, 'line_text' => string, 'from' => string, 'to' => string, 'source' => string]`
 * where `source` is the whole mutated file.
 *
 * @return list<array{operator: string, line: int, line_text: string, from: string, to: string, source: string}>
 */
function mutants(string $source): array
{
    /** @var list<array{int|null, string, int}> $tokens */
    $tokens = [];
    $line = 1;
    foreach (token_get_all($source) as $token) {
        if (\is_array($token)) {
            $tokens[] = [$token[0], $token[1], $token[2]];
            $line = $token[2] + substr_count($token[1], "\n");
        } else {
            $tokens[] = [null, $token, $line];
        }
    }

    $lines = explode("\n", $source);
    $out = [];
    $declareDepth = 0;
    $count = \count($tokens);
    for ($i = 0; $i < $count; $i++) {
        [$id, $text, $at] = $tokens[$i];

        // The `declare(strict_types=1)` statement is not behavior under test.
        if ($id === T_DECLARE) {
            $declareDepth = 1;
        }
        if ($declareDepth === 1) {
            if ($text === ';') {
                $declareDepth = 0;
            }

            continue;
        }

        $replacement = null;
        $operator = '';
        if ($id === T_IS_IDENTICAL) {
            [$operator, $replacement] = ['IdenticalToNotIdentical', '!=='];
        } elseif ($id === T_IS_NOT_IDENTICAL) {
            [$operator, $replacement] = ['NotIdenticalToIdentical', '==='];
        } elseif ($id === T_IS_EQUAL) {
            [$operator, $replacement] = ['EqualToNotEqual', '!='];
        } elseif ($id === T_IS_NOT_EQUAL) {
            [$operator, $replacement] = ['NotEqualToEqual', '=='];
        } elseif ($id === T_IS_SMALLER_OR_EQUAL) {
            [$operator, $replacement] = ['LessOrEqualToLess', '<'];
        } elseif ($id === T_IS_GREATER_OR_EQUAL) {
            [$operator, $replacement] = ['GreaterOrEqualToGreater', '>'];
        } elseif ($id === T_BOOLEAN_AND) {
            [$operator, $replacement] = ['BooleanAndToBooleanOr', '||'];
        } elseif ($id === T_BOOLEAN_OR) {
            [$operator, $replacement] = ['BooleanOrToBooleanAnd', '&&'];
        } elseif ($id === null && $text === '<') {
            [$operator, $replacement] = ['LessToLessOrEqual', '<='];
        } elseif ($id === null && $text === '>') {
            [$operator, $replacement] = ['GreaterToGreaterOrEqual', '>='];
        } elseif ($id === null && $text === '!') {
            [$operator, $replacement] = ['RemoveNot', ''];
        } elseif ($id === T_STRING && \in_array(strtolower($text), ['true', 'false'], true) && ! isConstantName($tokens, $i)) {
            $operator = strtolower($text) === 'true' ? 'TrueToFalse' : 'FalseToTrue';
            $replacement = strtolower($text) === 'true' ? 'false' : 'true';
        } elseif ($id === null && \in_array($text, ['+', '-'], true) && isBinary($tokens, $i)) {
            [$operator, $replacement] = $text === '+' ? ['PlusToMinus', '-'] : ['MinusToPlus', '+'];
        }

        if ($replacement !== null) {
            $out[] = build($source, $tokens, $lines, $i, $operator, $replacement);
        }

        if ($id === T_LNUMBER && ctype_digit($text)) {
            $value = (int) $text;
            $out[] = build($source, $tokens, $lines, $i, 'IncrementInteger', (string) ($value + 1));
            if ($value > 0) {
                $out[] = build($source, $tokens, $lines, $i, 'DecrementInteger', (string) ($value - 1));
            }
        }
    }

    return $out;
}

/**
 * One mutant: the token at `$index` replaced.
 *
 * @param  list<array{int|null, string, int}>  $tokens
 * @param  list<string>  $lines
 * @return array{operator: string, line: int, line_text: string, from: string, to: string, source: string}
 */
function build(string $source, array $tokens, array $lines, int $index, string $operator, string $replacement): array
{
    $mutated = '';
    foreach ($tokens as $position => $token) {
        $mutated .= $position === $index ? $replacement : $token[1];
    }

    // A mutant that equals the source would be recorded as a survivor no test could kill.
    if ($mutated === $source) {
        throw new \LogicException("Mutant {$operator} at line {$tokens[$index][2]} left the source unchanged.");
    }

    $line = $tokens[$index][2];

    return [
        'operator' => $operator,
        'line' => $line,
        'line_text' => trim($lines[$line - 1] ?? ''),
        'from' => $tokens[$index][1],
        'to' => $replacement,
        'source' => $mutated,
    ];
}

/**
 * Whether the `true` or `false` at `$index` is part of a constant declaration or a namespaced or called name.
 *
 * @param  list<array{int|null, string, int}>  $tokens
 */
function isConstantName(array $tokens, int $index): bool
{
    $previous = previousSignificant($tokens, $index);
    $next = nextSignificant($tokens, $index);

    return $previous === T_CONST || $previous === T_NS_SEPARATOR || $next === '(' || $next === T_DOUBLE_COLON || $previous === T_DOUBLE_COLON || $previous === T_OBJECT_OPERATOR;
}

/**
 * Whether the `+` or `-` at `$index` has an operand on its left, so it is binary rather than a sign.
 *
 * @param  list<array{int|null, string, int}>  $tokens
 */
function isBinary(array $tokens, int $index): bool
{
    $previous = previousSignificant($tokens, $index);

    return \in_array($previous, [T_VARIABLE, T_LNUMBER, T_DNUMBER, T_STRING, ')', ']'], true);
}

/**
 * The id (or the character) of the closest non-whitespace, non-comment token before `$index`.
 *
 * @param  list<array{int|null, string, int}>  $tokens
 */
function previousSignificant(array $tokens, int $index): int|string|null
{
    for ($i = $index - 1; $i >= 0; $i--) {
        if (! \in_array($tokens[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            return $tokens[$i][0] ?? $tokens[$i][1];
        }
    }

    return null;
}

/**
 * The id (or the character) of the closest non-whitespace, non-comment token after `$index`.
 *
 * @param  list<array{int|null, string, int}>  $tokens
 */
function nextSignificant(array $tokens, int $index): int|string|null
{
    for ($i = $index + 1, $count = \count($tokens); $i < $count; $i++) {
        if (! \in_array($tokens[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            return $tokens[$i][0] ?? $tokens[$i][1];
        }
    }

    return null;
}

/**
 * The source with the kill control planted: a throw that runs as soon as the file is loaded.
 *
 * It goes after the `declare` and `namespace` statements, which PHP requires to come first, so the file still compiles and the
 * suite fails by running the throw rather than by a compile error.
 */
function killControl(string $source): string
{
    $at = 0;
    foreach (['/declare\(strict_types=1\);/', '/^namespace [^;{]+;/m'] as $statement) {
        if (preg_match($statement, $source, $match, PREG_OFFSET_CAPTURE, $at) === 1) {
            $at = $match[0][1] + \strlen($match[0][0]);
        }
    }
    if ($at === 0) {
        $at = strpos($source, '<?php');
        if ($at === false) {
            throw new \LogicException('The file has no PHP open tag.');
        }
        $at += 5;
    }

    return substr($source, 0, $at)."
throw new \RuntimeException('hand-mutation control');
".substr($source, $at);
}

/**
 * Run `$command` and return its exit code, the first failing test it names, and whether it timed out.
 *
 * `$finished` is a pattern the output of a finished run matches. A mutant can make the suite call `exit` and end with status 0
 * before it reports anything, which would read as a pass, so a run with status 0 and no match is returned as code 125.
 *
 * @return array{code: int, killer: string, timed_out: bool, seconds: float}
 */
function runSuite(string $command, int $timeout, string $finished = ''): array
{
    $started = microtime(true);
    // The output goes to a file rather than a pipe: a pipe read can block past the timeout on Windows.
    $log = tempnam(sys_get_temp_dir(), 'hm');
    $process = proc_open((PHP_OS_FAMILY === 'Windows' ? '' : 'exec ').$command, [0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'], 1 => ['file', $log, 'w'], 2 => ['file', $log, 'a']], $pipes);
    if (! \is_resource($process)) {
        throw new \RuntimeException("Could not start: {$command}");
    }

    $timedOut = false;
    while (true) {
        $status = proc_get_status($process);
        if (! $status['running']) {
            break;
        }
        if (microtime(true) - $started > $timeout) {
            $timedOut = true;
            proc_terminate($process, 9);
            break;
        }
        usleep(50000);
    }
    // `proc_get_status()` reports the exit code once only, so it is read from the loop's last status.
    $code = $timedOut ? 124 : (int) $status['exitcode'];
    proc_close($process);
    $output = (string) file_get_contents($log);
    unlink($log);

    $killer = '';
    if (preg_match('/^1\) (.+)$/m', $output, $match) === 1) {
        $killer = trim($match[1]);
    } elseif (preg_match('/^(?:PHP )?(?:Fatal error|Parse error|Uncaught)[^\n]*/mi', $output, $match) === 1) {
        $killer = trim($match[0]);
    }

    if ($code === 0 && $finished !== '' && preg_match($finished, $output) !== 1) {
        $code = 125;
        $killer = 'The suite stopped before it reported a result.';
    }

    return ['code' => $code, 'killer' => $killer, 'timed_out' => $timedOut, 'seconds' => microtime(true) - $started];
}

/**
 * The pattern a finished run of `$command` matches: Pest's summary when the command names Pest, otherwise PHPUnit's `OK` line.
 * A command that is neither (a test double in the unit tests) gets none, so its exit code alone decides.
 */
function finishedPattern(string $command): string
{
    if (str_contains($command, 'pest')) {
        return PEST_FINISHED;
    }

    return str_contains($command, 'phpunit') ? PHPUNIT_FINISHED : '';
}

/**
 * Whether the file parses.
 */
function lints(string $path): bool
{
    exec('php -l '.escapeshellarg($path).' 2>&1', $ignored, $code);

    return $code === 0;
}

/**
 * Select the mutants of shard `$index` of `$total` (1-based).
 *
 * @template T
 *
 * @param  list<T>  $items
 * @return list<T>
 */
function shard(array $items, int $index, int $total): array
{
    $picked = [];
    foreach ($items as $position => $item) {
        if ($position % $total === $index - 1) {
            $picked[] = $item;
        }
    }

    return $picked;
}

/**
 * The Markdown record of a run.
 *
 * @param  array<string, array<string, mixed>>  $results  Per class: counts and the rows.
 * @param  array<string, array{result: string, killer: string}>  $controls
 */
function report(array $results, array $controls, string $environment): string
{
    $md = "## Hand-mutation run\n\n{$environment}\n\n### Controls\n\n| Control | Expected | Result | Killed by |\n| --- | --- | --- | --- |\n";
    foreach ($controls as $name => $control) {
        $md .= "| {$name} | ".($name === 'Planted survivor' ? 'survived' : 'killed')." | {$control['result']} | ".($control['killer'] === '' ? 'n/a' : '`'.$control['killer'].'`')." |\n";
    }

    $md .= "\n### Classes\n\n| Class | Mutants | Killed by the integration suite | Killed by the unit suite | Survived (equivalent) | Survived (unexplained) | Invalid |\n| --- | --- | --- | --- | --- | --- | --- |\n";
    foreach ($results as $class => $data) {
        $md .= "| `{$class}` | {$data['total']} | {$data['killed']} | {$data['killed_unit']} | {$data['equivalent']} | {$data['survived']} | {$data['invalid']} |\n";
    }

    foreach ($results as $class => $data) {
        $rows = array_filter($data['rows'], static fn (array $row): bool => $row['result'] !== 'killed');
        if ($rows === []) {
            continue;
        }
        $md .= "\n### `{$class}`: mutants that were not killed\n\n| Mutant | Line | Change | Result |\n| --- | --- | --- | --- |\n";
        foreach ($rows as $row) {
            $change = '`'.str_replace('|', '\\|', $row['from']).'` to `'.str_replace('|', '\\|', $row['to']).'`';
            $md .= "| {$row['operator']} | {$row['line']} | {$change} | {$row['result']}".($row['reason'] !== '' ? ': '.$row['reason'] : '')." |\n";
        }
    }

    $md .= "\n### Every mutant\n\n<details><summary>Killed mutants, with the suite and the first failing test</summary>\n\n| Class | Mutant | Line | Suite | Killed by |\n| --- | --- | --- | --- | --- |\n";
    foreach ($results as $class => $data) {
        foreach ($data['rows'] as $row) {
            if ($row['result'] === 'killed') {
                $md .= "| `{$class}` | {$row['operator']} | {$row['line']} | {$row['suite']} | ".($row['killer'] === '' ? 'n/a' : '`'.str_replace('|', '\\|', $row['killer']).'`')." |\n";
            }
        }
    }

    return $md."\n</details>\n";
}

/**
 * Entry point.
 *
 * @param  list<string>  $argv
 */
function main(array $argv): int
{
    $options = [];
    foreach (\array_slice($argv, 1) as $argument) {
        if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $argument, $match) !== 1) {
            fwrite(STDERR, "Unknown argument: {$argument}\n");

            return 2;
        }
        $options[$match[1]] = $match[2] ?? '1';
    }

    $names = ($options['class'] ?? '') === 'all' ? array_keys(CLASSES) : array_filter(explode(',', $options['class'] ?? ''));
    $unknown = array_diff($names, array_keys(CLASSES));
    if ($names === [] || $unknown !== []) {
        fwrite(STDERR, 'Pass --class=<name>[,<name>] or --class=all. Known: '.implode(', ', array_keys(CLASSES))."\n");

        return 2;
    }

    $shardIndex = 1;
    $shardTotal = 1;
    if (isset($options['shard'])) {
        if (preg_match('#^(\d+)/(\d+)$#', $options['shard'], $match) !== 1 || (int) $match[1] < 1 || (int) $match[1] > (int) $match[2]) {
            fwrite(STDERR, "--shard must look like 2/4.\n");

            return 2;
        }
        [$shardIndex, $shardTotal] = [(int) $match[1], (int) $match[2]];
    }

    $root = \dirname(__DIR__);
    chdir($root);

    $plan = [];
    foreach ($names as $name) {
        $path = CLASSES[$name];
        $plan[$name] = shard(mutants((string) file_get_contents($path)), $shardIndex, $shardTotal);
    }

    if (isset($options['list'])) {
        foreach ($plan as $name => $list) {
            foreach ($list as $mutant) {
                echo "{$name}:{$mutant['line']}:{$mutant['operator']}  {$mutant['from']} -> {$mutant['to']}  {$mutant['line_text']}\n";
            }
            echo "{$name}: ".\count($list)." mutants\n";
        }

        return 0;
    }

    $command = $options['phpunit'] ?? DEFAULT_COMMAND;
    $unitCommand = ($options['unit'] ?? UNIT_COMMAND) === 'none' ? null : ($options['unit'] ?? UNIT_COMMAND);

    // The unmutated suite must pass: otherwise every mutant reads as killed.
    $baseline = runSuite($command, 3600, finishedPattern($command));
    if ($baseline['code'] !== 0) {
        fwrite(STDERR, "The unmutated suite failed (exit {$baseline['code']}); no mutant can be scored.\n");

        return 2;
    }
    $timeout = isset($options['timeout']) ? (int) $options['timeout'] : max(60, (int) ceil($baseline['seconds'] * 4));
    fwrite(STDOUT, \sprintf("Baseline passed in %.1fs; each mutant is stopped after %ds.\n", $baseline['seconds'], $timeout));

    $equivalents = [];
    if (is_file(EQUIVALENTS_FILE)) {
        $decoded = json_decode((string) file_get_contents(EQUIVALENTS_FILE), true, 512, JSON_THROW_ON_ERROR);
        $equivalents = \is_array($decoded) ? $decoded : [];
    }

    // The source of whichever file is mutated at the moment, so a stop mid-run still puts it back.
    $backup = ['path' => '', 'source' => ''];
    $restore = static function () use (&$backup): void {
        if ($backup['path'] !== '') {
            file_put_contents($backup['path'], $backup['source']);
            $backup['path'] = '';
        }
    };
    register_shutdown_function($restore);
    if (\function_exists('pcntl_signal')) {
        pcntl_async_signals(true);
        foreach ([SIGINT, SIGTERM] as $signal) {
            pcntl_signal($signal, static function () use ($restore): void {
                $restore();
                exit(130);
            });
        }
    }

    $withSource = static function (string $path, string $mutated, callable $run) use (&$backup, $restore): mixed {
        $backup = ['path' => $path, 'source' => (string) file_get_contents($path)];
        try {
            file_put_contents($path, $mutated);

            return $run();
        } finally {
            $restore();
        }
    };

    // Controls: a mutant that cannot be killed must survive, and one that cannot be missed must be killed.
    $controlPath = CLASSES[$names[0]];
    $controlSource = (string) file_get_contents($controlPath);
    $controls = [];
    $survive = $withSource($controlPath, $controlSource.SURVIVE_CONTROL, static fn (): array => runSuite($command, $timeout, finishedPattern($command)));
    $controls['Planted survivor'] = ['result' => $survive['code'] === 0 ? 'survived' : 'killed', 'killer' => $survive['killer']];
    $kill = $withSource($controlPath, killControl($controlSource), static fn (): array => runSuite($command, $timeout, finishedPattern($command)));
    $controls['Planted kill (throws on load)'] = ['result' => $kill['code'] !== 0 ? 'killed' : 'survived', 'killer' => $kill['killer']];
    echo "Control, planted survivor in {$controlPath}: {$controls['Planted survivor']['result']}\n";
    echo "Control, throw on load in {$controlPath}: {$controls['Planted kill (throws on load)']['result']}\n";
    $controlsOk = $controls['Planted survivor']['result'] === 'survived' && $controls['Planted kill (throws on load)']['result'] === 'killed';

    $results = [];
    $unexplained = 0;
    if ($controlsOk) {
        foreach ($plan as $name => $list) {
            $path = CLASSES[$name];
            $data = ['total' => \count($list), 'killed' => 0, 'killed_unit' => 0, 'equivalent' => 0, 'survived' => 0, 'invalid' => 0, 'rows' => []];
            foreach ($list as $number => $mutant) {
                $row = ['operator' => $mutant['operator'], 'line' => $mutant['line'], 'from' => $mutant['from'], 'to' => $mutant['to'], 'killer' => '', 'suite' => 'integration', 'reason' => '', 'result' => ''];
                $outcome = $withSource($path, $mutant['source'], static function () use ($path, $command, $timeout): ?array {
                    return lints($path) ? runSuite($command, $timeout, finishedPattern($command)) : null;
                });
                if ($outcome === null) {
                    $row['result'] = 'invalid';
                    $data['invalid']++;
                } elseif ($outcome['code'] !== 0) {
                    $row['result'] = 'killed';
                    $row['killer'] = $outcome['timed_out'] ? 'timeout' : $outcome['killer'];
                    $data['killed']++;
                } elseif ($unitCommand !== null && ($unit = $withSource($path, $mutant['source'], static fn (): array => runSuite($unitCommand, $timeout, finishedPattern($unitCommand))))['code'] !== 0) {
                    $row['result'] = 'killed';
                    $row['suite'] = 'unit';
                    $row['killer'] = $unit['timed_out'] ? 'timeout' : $unit['killer'];
                    $data['killed_unit']++;
                } else {
                    $reason = '';
                    foreach ($equivalents as $entry) {
                        if (($entry['class'] ?? '') === $name && ($entry['operator'] ?? '') === $mutant['operator'] && ($entry['line_text'] ?? '') === $mutant['line_text']) {
                            $reason = (string) ($entry['reason'] ?? '');
                        }
                    }
                    if ($reason !== '') {
                        $row['result'] = 'equivalent';
                        $row['reason'] = $reason;
                        $data['equivalent']++;
                    } else {
                        $row['result'] = 'survived';
                        $data['survived']++;
                        $unexplained++;
                    }
                }
                $data['rows'][] = $row;
                printf("%s %d/%d  %s line %d  %s\n", $name, $number + 1, \count($list), $mutant['operator'], $mutant['line'], $row['result']);
            }
            $results[$name] = $data;
        }
    }

    $environment = \sprintf('PHP %s on %s, shard %d of %d. Suite command: `%s`. Baseline %.1fs.', PHP_VERSION, PHP_OS_FAMILY, $shardIndex, $shardTotal, $command, $baseline['seconds']);
    $markdown = report($results, $controls, $environment);
    echo "\n".$markdown;
    if (isset($options['report'])) {
        file_put_contents($options['report'], $markdown);
    }
    if (isset($options['json'])) {
        file_put_contents($options['json'], json_encode(['environment' => $environment, 'controls' => $controls, 'results' => $results], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }
    $summary = getenv('GITHUB_STEP_SUMMARY');
    if (\is_string($summary) && $summary !== '') {
        file_put_contents($summary, $markdown, FILE_APPEND);
    }

    if (! $controlsOk) {
        fwrite(STDERR, "A control behaved wrongly, so the run is not trustworthy.\n");

        return 3;
    }

    return isset($options['fail-on-survivor']) && $unexplained > 0 ? 1 : 0;
}

if (isset($_SERVER['argv'][0]) && realpath($_SERVER['argv'][0]) === __FILE__) {
    exit(main($_SERVER['argv']));
}
