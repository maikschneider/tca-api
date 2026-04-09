<?php

declare(strict_types=1);

use MaikSchneider\TcaApi\Enum\AccessRole;
use MaikSchneider\TcaApi\Serializer\FileProcessing\FileProcessor;

return [
    'general' => [
        'table' => 'tx_myext_domain_model_article',
        'resourceName' => 'articles',
        'resourceType' => 'Article',
        'operations' => ['list', 'show', 'create', 'update', 'delete'],
        'itemsPerPage' => 20,
    ],
    'columns' => [
        'title' => [
            'type' => 'string',
            'readable' => true,
            'writable' => true,
            'required' => true,
            'validators' => [
                ['type' => 'maxLength', 'max' => 20],
                ['type' => 'minLength', 'min' => 3],
                ['type' => 'regex', 'pattern' => '/^[\w\s]+$/u'],
            ],
        ],
        'color_id' => [
            'readable' => true,
            'writable' => true,
        ],
        'categories' => [
            'readable' => true,
            'writable' => true,
        ],
        'profile_photo' => [
            'readable' => true,
        ],
        'downloads' => [
            'readable'  => true,
            'processor' => FileProcessor::class,
        ],
    ],
    'filters' => [
        'title'      => ['strategy' => 'exact'],
        'color_id'   => ['strategy' => 'exact'],
        'categories' => ['strategy' => 'mm'],
    ],
    'order' => [
        'allowed' => ['title', 'uid'],
        'default' => ['uid' => 'asc'],
    ],
    'security' => [
        'list'   => AccessRole::PUBLIC,
        'show'   => AccessRole::PUBLIC,
        'create' => AccessRole::FE_USER,
        'update' => AccessRole::FE_USER,
        'delete' => AccessRole::BE_ADMIN,
    ],
];
