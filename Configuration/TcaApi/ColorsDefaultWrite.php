<?php

declare(strict_types=1);

use MaikSchneider\TcaApi\Enum\AccessRole;

/**
 * Default-mode write fixture for TCA validator derivation tests.
 *
 * No explicit columns → isExplicitMode = false. The TCA for
 * tx_myext_domain_model_color declares input.max for name (255) and
 * hex (7), which TcaValidatorDeriver must inject automatically.
 */
return [
    'general' => [
        'table'        => 'tx_myext_domain_model_color',
        'resourceName' => 'colors-validate-default',
        'resourceType' => 'ColorValidateDefault',
        'operations'   => ['list', 'show', 'create', 'update', 'delete'],
        'storagePid'   => 1,
    ],
    'security' => [
        'list'   => AccessRole::PUBLIC,
        'show'   => AccessRole::PUBLIC,
        'create' => AccessRole::FE_USER,
        'update' => AccessRole::FE_USER,
        'delete' => AccessRole::FE_USER,
    ],
];
