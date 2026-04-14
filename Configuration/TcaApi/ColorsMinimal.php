<?php

declare(strict_types=1);

use MaikSchneider\TcaApi\Enum\AccessRole;

/**
 * Zero-config registration of the color table — no column visibility flags set.
 * All non-system TCA columns are auto-exposed (default mode).
 */
return [
    'general' => [
        'table'        => 'tx_myext_domain_model_color',
        'resourceName' => 'colors-minimal',
        'resourceType' => 'ColorMinimal',
        'operations'   => ['list', 'show'],
        'itemsPerPage' => 20,
    ],
    'security' => [
        'list' => AccessRole::PUBLIC,
        'show' => AccessRole::PUBLIC,
    ],
];
