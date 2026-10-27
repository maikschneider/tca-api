<?php

declare(strict_types=1);

/**
 * Writes the mismatches collected by a functional run (TCA_API_CONTRACT_COLLECT)
 * as the contract baseline of the installed TYPO3 major version.
 *
 * Usage: php Tests/Functional/Contract/write-baseline.php <collected-mismatches-file>
 */

use TYPO3\CMS\Core\Information\Typo3Version;

require __DIR__ . '/../../../vendor/autoload.php';

$collected = $argv[1] ?? '';
// A complete run without a single mismatch leaves no file behind.
$lines = is_file($collected) ? (file($collected, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : [];
$lines = array_values(array_unique($lines));
sort($lines, SORT_STRING);

$major  = (new Typo3Version())->getMajorVersion();
$target = __DIR__ . '/openapi-contract-baseline.v' . $major . '.txt';
$header = [
    '# Known mismatches between API responses and the OpenAPI spec on TYPO3 ' . $major . ', one per line.',
    '# See README.md in this directory before editing.',
];

file_put_contents($target, implode("\n", [...$header, ...$lines]) . "\n");
echo 'Wrote ' . count($lines) . ' mismatches to ' . basename($target) . PHP_EOL;
