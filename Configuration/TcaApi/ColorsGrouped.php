<?php

declare(strict_types=1);

use MaikSchneider\TcaApi\Enum\AccessRole;

/**
 * Groups-based registration of the color table — explicit mode via 'groups' key.
 * 'name' appears in both list and show; 'hex' appears only in show.
 */
return [
    'general' => [
        'table'        => 'tx_myext_domain_model_color',
        'resourceName' => 'colors-grouped',
        'resourceType' => 'ColorGrouped',
        'itemsPerPage' => 20,
    ],
    'columns' => [
        'name' => ['groups' => ['list', 'show']],
        'hex'  => ['groups' => ['show']],
    ],
    'security' => [
        'list' => AccessRole::PUBLIC,
        'show' => AccessRole::PUBLIC,
    ],
];
