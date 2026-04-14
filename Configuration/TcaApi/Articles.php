<?php

declare(strict_types=1);

use MaikSchneider\TcaApi\Enum\AccessRole;
use MaikSchneider\TcaApi\Serializer\FileProcessing\FileProcessor;
use MaikSchneider\TcaApi\Serializer\Processing\TypoLinkProcessor;

return [
    'general' => [
        'table' => 'tx_myext_domain_model_article',
        'resourceName' => 'articles',
        'resourceType' => 'Article',
        'operations' => ['list', 'show', 'create', 'update', 'delete'],
    ],
    'columns' => [
        'title' => [
            'type' => 'string',
            'groups' => ['list', 'show', 'create', 'update'],
            'required' => true,
            'validators' => [
                ['type' => 'maxLength', 'max' => 20],
                ['type' => 'minLength', 'min' => 3],
                ['type' => 'regex', 'pattern' => '/^[\w\s]+$/u'],
            ],
        ],
        'color_id' => [
            'groups' => ['list', 'show', 'create', 'update'],
        ],
        'categories' => [
            'groups' => ['list', 'show', 'create', 'update'],
        ],
        'profile_photo' => [
            'groups' => ['list', 'show'],
        ],
        'downloads' => [
            'groups'    => ['list', 'show'],
            'processor' => FileProcessor::class,
        ],
        'article_url' => [
            'groups'    => ['list', 'show'],
            'processor' => TypoLinkProcessor::class,
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
