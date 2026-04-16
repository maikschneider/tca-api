<?php

declare(strict_types=1);

use MaikSchneider\TcaApi\Enum\AccessRole;

return [
    'general' => [
        'table'        => 'tx_myext_domain_model_color',
        'resourceName' => 'colors-empty-groups',
        'resourceType' => 'ColorEmptyGroups',
        'operations'   => ['list', 'show'],
        'itemsPerPage' => 20,
    ],
    'columns' => [
        'name' => ['groups' => []],
    ],
    'security' => [
        'list' => AccessRole::PUBLIC,
        'show' => AccessRole::PUBLIC,
    ],
];
