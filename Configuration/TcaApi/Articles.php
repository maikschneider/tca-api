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
        'category_id' => [
            'type' => 'hasOne',
            'readable' => true,
            'writable' => false,
            'foreignTable' => 'tx_myext_domain_model_category',
            'foreignResourceName' => 'categories',
            'foreignResourceType' => 'Category',
        ],
        'tags' => [
            'type' => 'manyToMany',
            'readable' => true,
            'writable' => false,
            'foreignTable' => 'tx_myext_domain_model_tag',
            'foreignResourceName' => 'tags',
            'foreignResourceType' => 'Tag',
            'mmTable' => 'tx_myext_article_tag_mm',
            'mmLocalKey' => 'uid_local',
            'mmForeignKey' => 'uid_foreign',
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
        'list'   => AccessRole::PUBLIC,
        'show'   => AccessRole::PUBLIC,
        'create' => AccessRole::FE_USER,
        'update' => AccessRole::FE_USER,
        'delete' => AccessRole::BE_ADMIN,
    ],
];
