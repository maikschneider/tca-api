<?php

declare(strict_types=1);

use MaikSchneider\TcaApi\Enum\AccessRole;
use MaikSchneider\TcaApi\Filter\ExactFilter;
use MaikSchneider\TcaApi\Filter\MmFilter;

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
            'groups' => ['list', 'show', 'create', 'update'],
            'required' => true,
            'validators' => [
                ['type' => 'maxLength', 'max' => 20],
                ['type' => 'minLength', 'min' => 3],
                ['type' => 'regex', 'pattern' => '/^[\w\s]+$/u'],
            ],
        ],
        'bodytext' => [
            'groups' => ['list', 'show', 'create', 'update'],
        ],
        'fal_media' => [
            'groups' => ['list', 'show'],
        ],
    ],
    'filters' => [
        'title'      => ExactFilter::class,
        'categories' => MmFilter::class,
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
