<?php

declare(strict_types=1);

return [
    'frontend' => [
        'maikschneider/tca-api/middleware' => [
            'target' => \MaikSchneider\TcaApi\Middleware\TcaApiMiddleware::class,
            'before' => [
                'typo3/cms-frontend/page-resolver',
            ],
            'after' => [
                'typo3/cms-frontend/site',
                'typo3/cms-frontend/authentication',
                'typo3/cms-frontend/backend-user-authentication',
                'typo3/cms-frontend/base-redirect-resolver',
            ],
        ],
    ],
];
