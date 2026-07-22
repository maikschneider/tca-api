<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api;

use MaikSchneider\TcaApi\Controller\ApiDocumentationController;
use MaikSchneider\TcaApi\Dispatcher\RequestContext;
use MaikSchneider\TcaApi\OpenApi\OpenApiBuilder;
use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Module\ModuleData;
use TYPO3\CMS\Backend\Module\ModuleProvider;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Backend\Template\Components\ComponentFactory;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
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
        if (!class_exists(ComponentFactory::class)) {
            self::markTestSkipped('The Integrations backend module is TYPO3 v14+ only.');
        }

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

    public function testIndexActionRendersSwaggerUiMountAndAssets(): void
    {
        if (!class_exists(ComponentFactory::class)) {
            self::markTestSkipped('The Integrations backend module is TYPO3 v14+ only.');
        }

        $response = $this->renderModule();
        self::assertSame(200, $response->getStatusCode());
        $html = (string)$response->getBody();

        // The Swagger mount point and inline bootstrap are rendered into the module body.
        self::assertStringContainsString('id="tca-api-swagger-ui"', $html);
        self::assertStringContainsString('SwaggerUIBundle(', $html);

        // All four static assets are wired: the vendored bundle plus the two
        // backend-only override files that carry the theme + title/servers fixes.
        self::assertStringContainsString('swagger-ui-bundle.js', $html);
        self::assertStringContainsString('swagger-ui.css', $html);
        self::assertStringContainsString('swagger-ui-backend.css', $html);
        self::assertStringContainsString('swagger-ui-backend.js', $html);
    }

    public function testIndexActionServerUrlIsOriginSoTryItOutDoesNotDoublePrefix(): void
    {
        if (!class_exists(ComponentFactory::class)) {
            self::markTestSkipped('The Integrations backend module is TYPO3 v14+ only.');
        }

        $html = (string)$this->renderModule()->getBody();

        // Regression guard for the doubled-prefix bug ("Try it out" → /_api/_api/articles).
        // The inlined spec is encoded with JSON_HEX_QUOT, so double quotes render as ".
        // servers[0].url must be the bare site origin because the path keys already carry
        // the "/_api" prefix; appending the prefix to the server URL would double it.
        self::assertStringContainsString('"url":"http://localhost"', $html);
        self::assertStringContainsString('"/_api/articles"', $html);
    }

    public function testDownloadActionServesSpecAsOpenApiJsonAttachment(): void
    {
        if (!class_exists(ComponentFactory::class)) {
            self::markTestSkipped('The Integrations backend module is TYPO3 v14+ only.');
        }

        $request = (new ServerRequest('http://localhost/typo3/module/integrations/tca-api/download'))
            ->withQueryParams(['site' => 'main']);
        $response = $this->get(ApiDocumentationController::class)->downloadAction($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame(
            'attachment; filename="openapi.json"',
            $response->getHeaderLine('Content-Disposition'),
        );

        // The downloaded file is the same access-gate-free spec the module renders,
        // with the site origin as server URL (no doubled "/_api" prefix).
        $spec = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('3.1.0', $spec['openapi']);
        self::assertSame('http://localhost', $spec['servers'][0]['url']);
        self::assertArrayHasKey('/_api/articles', $spec['paths']);
    }

    public function testDownloadActionFallsBackToFirstSiteForUnknownSiteParam(): void
    {
        if (!class_exists(ComponentFactory::class)) {
            self::markTestSkipped('The Integrations backend module is TYPO3 v14+ only.');
        }

        $request = (new ServerRequest('http://localhost/typo3/module/integrations/tca-api/download'))
            ->withQueryParams(['site' => 'does-not-exist']);
        $response = $this->get(ApiDocumentationController::class)->downloadAction($request);

        self::assertSame(200, $response->getStatusCode());
        $spec = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertNotEmpty($spec['paths']);
    }

    /**
     * Invoke the controller's indexAction directly, bypassing the backend module routing and template wiring.
     */
    private function renderModule(): ResponseInterface
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $backendUser = $this->setUpBackendUser(2);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);

        $module = $this->get(ModuleProvider::class)->getModule('tca_api_documentation', $backendUser);
        self::assertNotNull($module, 'The tca_api_documentation module must be registered on TYPO3 v14+.');

        $route = new Route('/module/integrations/tca-api', $module->getDefaultRouteOptions()['_default']);
        $moduleData = ModuleData::createFromModule($module, ['site' => '']);

        $request = (new ServerRequest('http://localhost/typo3/module/integrations/tca-api', 'GET', 'php://temp', [], [
            'HTTP_HOST' => 'localhost',
            'SCRIPT_NAME' => '/typo3/index.php',
            'REQUEST_URI' => '/typo3/module/integrations/tca-api',
        ]))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('module', $module)
            ->withAttribute('moduleData', $moduleData)
            ->withAttribute('route', $route);
        $request = $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));

        return $this->get(ApiDocumentationController::class)->indexAction($request);
    }
}
