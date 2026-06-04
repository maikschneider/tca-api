<?php

declare(strict_types=1);

use MaikSchneider\TcaApi\Serializer\Processing\RouteEnhancerProcessor;

/**
 * Demo resource for the News extension.
 *
 * The `url` virtual property generates a speaking URL for every record using
 * the routeEnhancer configured in config/sites/bootstrap/config.yaml
 * (`NewsPlugin` → `/{news_title}`). The processor delegates to the TYPO3 site
 * router, so the routeEnhancer transforms the query into `/news/my-article`
 * automatically.
 *
 * Swap the literal `pid` for a site-settings placeholder once a setting is
 * registered (e.g. `'pid' => '{$tca_api.news.detailPid}'`).
 */
return [
    'general' => [
        'table'        => 'tx_news_domain_model_news',
        'resourceName' => 'news',
        'resourceType' => 'News',
    ],
    'virtualProperties' => [
        'url' => [
            'processor' => RouteEnhancerProcessor::class,
            'route' => [
                'pid'        => 3,
                'extension'  => 'News',
                'plugin'     => 'Pi1',
                'controller' => 'News',
                'action'     => 'detail',
                'arguments'  => ['news' => '{uid}'],
            ],
        ],
    ],
];
