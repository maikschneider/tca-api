<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api;

use MaikSchneider\TcaApi\Controller\ApiDocumentationController;
use MaikSchneider\TcaApi\Dispatcher\RequestContext;
use MaikSchneider\TcaApi\OpenApi\OpenApiBuilder;
use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Covers the "Integrations" backend module that renders the Swagger UI.
 *
 * The module builds its OpenAPI specification directly from a site's settings
 * via {@see OpenApiBuilder}, deliberately skipping the dispatcher-level access
 * gates (`tca_api.enabled`, `tca_api.swaggerUiEnabled`, `tca_api.openApiExposed`).
 * These tests pin that reused build path and the controller's DI wiring.
 */
final class ApiDocumentationModuleTest extends ApiFunctionalTestCase
{
    public function testControllerResolvesWithAllDependencies(): void
    {
        // Fails if any injected backend service (ComponentFactory, PageRenderer,
        // ModuleTemplateFactory, OpenApiBuilder, …) cannot be wired.
        self::assertInstanceOf(
            ApiDocumentationController::class,
            $this->get(ApiDocumentationController::class),
        );
    }

    public function testSpecificationIsBuiltFromSiteSettings(): void
    {
        $site = $this->get(SiteFinder::class)->getSiteByIdentifier('main');

        // Mirror ApiDocumentationController::buildSpecification().
        $context = new RequestContext($site->getSettings(), new ServerRequest($site->getBase()));
        $specification = $this->get(OpenApiBuilder::class)->build($context);

        self::assertSame('3.1.0', $specification['openapi']);
        self::assertArrayHasKey('/_api/articles', $specification['paths']);
        self::assertNotEmpty($specification['paths']['/_api/articles']['get'] ?? null);
    }

    public function testBuildIgnoresAccessGatesSoDocsAreAlwaysAvailable(): void
    {
        // The build path never consults the swagger/openapi site-setting gates —
        // it produces the full spec regardless of how the API is exposed publicly.
        $site = $this->get(SiteFinder::class)->getSiteByIdentifier('main');
        $context = new RequestContext($site->getSettings(), new ServerRequest($site->getBase()));

        $specification = $this->get(OpenApiBuilder::class)->build($context);

        self::assertNotEmpty($specification['paths']);
    }
}
