<?php

/**
 * Pest configuration for the unit suite.
 *
 * The suite is class-based PHPUnit tests that Pest runs unchanged. The
 * WordPress stand-ins live in tests/bootstrap.php. The `wp eval-file`
 * integration scripts (the others beside it) and the PHPUnit 9.6
 * suite under tests/Integration are not
 * PHPUnit tests and Pest never loads them.
 */

declare(strict_types=1);
