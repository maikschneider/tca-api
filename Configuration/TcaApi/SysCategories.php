<?php

declare(strict_types=1);

use MaikSchneider\TcaApi\Enum\AccessRole;

return [
    'general' => [
        'table' => 'sys_category',
        'resourceName' => 'sys-categories',
        'resourceType' => 'SysCategory',
        'operations' => ['list', 'show'],
        'itemsPerPage' => 20,
    ],
    'columns' => [
        'title' => [
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
