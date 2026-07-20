<?php

use MaikSchneider\TcaApi\Controller\ApiDocumentationController;
use TYPO3\CMS\Backend\Template\Components\ComponentFactory;

/**
 * Backend module registration.
 *
 * Registers the "TCA API" documentation module as a submodule of the TYPO3 v14
 * "Integrations" main module (sibling to Reactions and Webhooks). It renders the
 * OpenAPI specification through Swagger UI and is always available to admins —
 * independent of the `tca_api.enabled` / `tca_api.swaggerUiEnabled` site settings
 * that gate the public frontend endpoints.
 *
 * The "Integrations" main module and the ComponentFactory the controller depends
 * on are TYPO3 v14+ only. On v13 no module is registered (and the controller is
 * not wired into the container — see Configuration/Services.php).
 */
if (!class_exists(ComponentFactory::class)) {
    return [];
}

return [
    'tca_api_documentation' => [
        'parent' => 'integrations',
        'position' => ['after' => '*'],
        'access' => 'admin',
        'workspaces' => 'live',
        'path' => '/module/integrations/tca-api',
        'iconIdentifier' => 'module-tca-api',
        'labels' => 'LLL:EXT:tca_api/Resources/Private/Language/locallang_mod.xlf',
        'moduleData' => [
            'site' => '',
        ],
        'routes' => [
            '_default' => [
                'target' => ApiDocumentationController::class . '::indexAction',
            ],
        ],
    ],
];
