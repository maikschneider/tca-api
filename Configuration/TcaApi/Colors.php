<?php

declare(strict_types=1);

use MaikSchneider\TcaApi\Enum\AccessRole;

return [
    'general' => [
        'table' => 'tx_myext_domain_model_color',
        'resourceName' => 'colors',
        'resourceType' => 'Color',
        'operations' => ['list', 'show'],
        'itemsPerPage' => 20,
    ],
    'columns' => [
        'name' => [
            'type' => 'string',
            'readable' => true,
            'writable' => false,
        ],
    ],
    'security' => [
        'list' => AccessRole::PUBLIC,
        'show' => AccessRole::PUBLIC,
    ],
];
