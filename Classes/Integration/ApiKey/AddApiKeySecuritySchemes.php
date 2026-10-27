<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Integration\ApiKey;

use MaikSchneider\TcaApi\Event\ModifyOpenApiSecuritySchemesEvent;
use MaikSchneider\TcaApi\OpenApi\SecurityScheme;
use TYPO3\CMS\Core\Attribute\AsEventListener;

/**
 * Documents both ways to send a key of the `api_key` extension. A key may be
 * bound to a frontend or backend user, so both schemes can satisfy user roles.
 */
#[AsEventListener('tca-api/api-key-security-schemes')]
final class AddApiKeySecuritySchemes
{
    public function __invoke(ModifyOpenApiSecuritySchemesEvent $event): void
    {
        $event->addScheme(new SecurityScheme(
            'ApiKey',
            ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-API-Key', 'description' => 'API key of the api_key extension.'],
            authenticatesFrontendUsers: true,
            authenticatesBackendUsers: true,
            isCredential: true,
        ));
        $event->addScheme(new SecurityScheme(
            'ApiKeyBearer',
            ['type' => 'http', 'scheme' => 'bearer', 'description' => 'API key of the api_key extension, sent as bearer token.'],
            authenticatesFrontendUsers: true,
            authenticatesBackendUsers: true,
            isCredential: true,
        ));
    }
}
