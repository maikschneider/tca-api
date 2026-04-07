<?php

declare(strict_types=1);

use MaikSchneider\TcaApi\Enum\AccessRole;

return [
    'general' => [
        'table' => 'tx_myext_domain_model_article',
        'resourceName' => 'articles',
        'resourceType' => 'Article',
        'operations' => ['list', 'show'],
        'itemsPerPage' => 20,
    ],
    'columns' => [
        'title' => [
            'type' => 'string',
            'readable' => true,
            'writable' => true,
        ],
    ],
    'filters' => [
        'title' => ['strategy' => 'exact'],
    ],
    'order' => [
        'allowed' => ['title', 'uid'],
        'default' => ['uid' => 'asc'],
    ],
    'security' => [
        'list' => AccessRole::PUBLIC,
        'show' => AccessRole::PUBLIC,
    ],
];
