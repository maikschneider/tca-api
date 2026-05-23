<?php

declare(strict_types=1);

// Test fixture — creates a loader-test-resource endpoint backed by the articles table.
// Does not modify any existing resource; safe for all test suites.
$GLOBALS['TCA_API']['loader-test-resource'] = [
    'general' => [
        'table'        => 'tx_myext_domain_model_article',
        'resourceName' => 'loader-test-resource',
        'resourceType' => 'LoaderTestResource',
        'storagePid'   => 1,
    ],
];
