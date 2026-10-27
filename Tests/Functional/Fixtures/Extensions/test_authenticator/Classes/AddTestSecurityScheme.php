<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApiTestAuthenticator;

use MaikSchneider\TcaApi\Event\ModifyOpenApiSecuritySchemesEvent;
use MaikSchneider\TcaApi\OpenApi\SecurityScheme;
use TYPO3\CMS\Core\Attribute\AsEventListener;

#[AsEventListener('test-authenticator/security-scheme')]
final class AddTestSecurityScheme
{
    public function __invoke(ModifyOpenApiSecuritySchemesEvent $event): void
    {
        $event->addScheme(new SecurityScheme(
            'TestAuth',
            ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-Test-Auth'],
            authenticatesFrontendUsers: true,
            authenticatesBackendUsers: true,
            isCredential: true,
        ));
    }
}
