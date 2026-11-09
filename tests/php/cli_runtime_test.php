<?php

/**
 * Verify that the PHP runtime executing the regression suite is the expected CLI minor.
 * This prevents a CI runner from silently testing with a different preinstalled PHP binary.
 */

declare(strict_types=1);

$expected = getenv('CREDENTIALS_EXPECTED_PHP_MINOR');
if (!is_string($expected) || !preg_match('/^8\.(?:3|4|5|6)$/', $expected)) {
    fwrite(STDERR, "CREDENTIALS_EXPECTED_PHP_MINOR must be one of 8.3, 8.4, 8.5 or 8.6.\n");
    exit(2);
}

$actual = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, 'Regression tests must run through PHP CLI; current SAPI: ' . PHP_SAPI . "\n");
    exit(1);
}
if ($actual !== $expected) {
    fwrite(STDERR, "Expected PHP CLI {$expected}; received {$actual} (" . PHP_VERSION . ").\n");
    exit(1);
}

fwrite(STDOUT, 'Verified PHP CLI ' . PHP_VERSION . ' (' . PHP_SAPI . ").\n");
