<?php

declare(strict_types=1);

use MaikSchneider\TcaApi\Enum\AccessRole;
use MaikSchneider\TcaApi\Filter\ExactFilter;
use MaikSchneider\TcaApi\Filter\MmFilter;
use MaikSchneider\TcaApi\Serializer\FileProcessing\FileProcessor;
use MaikSchneider\TcaApi\Serializer\Processing\TypoLinkProcessor;

return [
    'general' => [
        'table' => 'tx_myext_domain_model_article',
        'resourceName' => 'articles',
        'resourceType' => 'Article',
        'operations' => ['list', 'show', 'create', 'update', 'delete'],
        'storagePid' => 1,
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
            'resourceName' => 'colors',
        ],
        'categories' => [
            'groups' => ['list', 'show', 'create', 'update'],
        ],
        'profile_photo' => [
            'groups' => ['list', 'show', 'create', 'update'],
            'upload' => [
                'folder'  => '1:/user_upload/',
                'maxSize' => '5M',
                // Allowed extensions are read from TCA: type=file, allowed='jpg,jpeg,png,gif,webp'
            ],
        ],
        'first_name' => [
            'groups' => ['list', 'show', 'create', 'update'],
        ],
        'downloads' => [
            'groups'    => ['list', 'show', 'create', 'update'],
            'processor' => FileProcessor::class,
            'upload'    => [
                'folder'  => '1:/user_upload/',
                'maxSize' => '20M',
                // Allowed extensions are read from TCA: type=file, allowed='pdf,csv,xlsx,docx'
            ],
        ],
        'article_url' => [
            'groups'    => ['list', 'show'],
            'processor' => TypoLinkProcessor::class,
        ],
    ],
    'filters' => [
        'title'      => ExactFilter::class,
        'color_id'   => ExactFilter::class,
        'categories' => MmFilter::class,
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
