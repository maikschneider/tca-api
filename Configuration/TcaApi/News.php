<?php

declare(strict_types=1);

use MaikSchneider\TcaApi\Enum\AccessRole;

return [
    'general' => [
        'table' => 'tx_news_domain_model_news',
        'resourceName' => 'news',
        'resourceType' => 'News',
        'operations' => ['list', 'show', 'create', 'update', 'delete'],
        'itemsPerPage' => 20,
    ],
    'columns' => [
        'title' => [
            'readable' => true,
            'writable' => true,
            'required' => true,
            'validators' => [
                ['type' => 'maxLength', 'max' => 20],
                ['type' => 'minLength', 'min' => 3],
                ['type' => 'regex', 'pattern' => '/^[\w\s]+$/u'],
            ],
        ],
        'bodytext' => [
            'readable' => true,
            'writable' => true,
        ],
        'fal_media' => [
            'readable' => true,
        ],
    ],
    'filters' => [
        'title' => ['strategy' => 'exact'],
        'categories' => ['strategy' => 'mm'],
    ],
    'order' => [
        'allowed' => ['title', 'uid'],
        'default' => ['uid' => 'asc'],
    ],
    'security' => [
        'list' => AccessRole::PUBLIC,
        'show' => AccessRole::PUBLIC,
        'create' => AccessRole::FE_USER,
        'update' => AccessRole::FE_USER,
        'delete' => AccessRole::BE_ADMIN,
    ],
];
