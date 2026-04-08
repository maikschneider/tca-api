<?php

declare(strict_types=1);

use MaikSchneider\TcaApi\Enum\AccessRole;

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
    ],
    'filters' => [
        'title'      => ['strategy' => 'exact'],
        'color_id'   => ['strategy' => 'exact'],
        'categories' => [
            'strategy'       => 'mm',
            'mm_table'       => 'sys_category_record_mm',
            'mm_local_key'   => 'uid_local',
            'mm_foreign_key' => 'uid_foreign',
            'mm_constraints' => [
                'tablenames' => 'tx_myext_domain_model_article',
                'fieldname'  => 'categories',
            ],
        ],
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
