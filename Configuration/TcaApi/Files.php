<?php

declare(strict_types=1);

use MaikSchneider\TcaApi\Enum\AccessRole;

return [
    'general' => [
        'table' => 'sys_file',
        'resourceName' => 'files',
        'resourceType' => 'FileUpload',
        'type' => 'fileUpload',
        'operations' => ['create'],
        'storageUid' => 1,
    ],
    'columns' => [
        'name' => [
            'type' => 'string',
            'readable' => true,
        ],
        'identifier' => [
            'type' => 'string',
            'readable' => true,
        ],
        'mimeType' => [
            'type' => 'string',
            'readable' => true,
        ],
        'size' => [
            'type' => 'integer',
            'readable' => true,
        ],
    ],
    'security' => [
        'create' => AccessRole::FE_USER,
    ],
];
