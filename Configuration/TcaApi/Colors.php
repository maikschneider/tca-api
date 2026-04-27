<?php

declare(strict_types=1);

use MaikSchneider\TcaApi\Enum\AccessRole;

return [
    'general' => [
        'table' => 'tx_myext_domain_model_color',
        'resourceName' => 'colors',
        'resourceType' => 'Color',
        'itemsPerPage' => 20,
    ],
    'columns' => [
        'name' => [
            'type' => 'string',
            'groups' => ['list', 'show'],
        ],
    ],
    'security' => [
        'list' => AccessRole::PUBLIC,
        'show' => AccessRole::PUBLIC,
    ],
];
