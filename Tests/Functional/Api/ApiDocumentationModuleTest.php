<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api;

use MaikSchneider\TcaApi\Controller\ApiDocumentationController;
use MaikSchneider\TcaApi\Dispatcher\RequestContext;
use MaikSchneider\TcaApi\OpenApi\OpenApiBuilder;
use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Module\ModuleData;
use TYPO3\CMS\Backend\Module\ModuleProvider;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\Components\ComponentFactory;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Site\Entity\Site;
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

    public function testIndexActionRendersNoSitesInfoboxWhenNoSiteIsConfigured(): void
    {
        if (!class_exists(ComponentFactory::class)) {
            self::markTestSkipped('The Integrations backend module is TYPO3 v14+ only.');
        }

        // Drive the empty-set early-return branch with an empty SiteFinder.
        $siteFinder = $this->createMock(SiteFinder::class);
        $siteFinder->method('getAllSites')->willReturn([]);

        $response = $this->controllerWithSiteFinder($siteFinder)->indexAction($this->buildBackendRequest());

        self::assertSame(200, $response->getStatusCode());
        // The "not enabled" infobox is rendered instead of Swagger UI.
        self::assertStringContainsString('No site has the TCA API enabled.', (string)$response->getBody());
        self::assertStringNotContainsString('id="tca-api-swagger-ui"', (string)$response->getBody());
    }

    public function testIndexActionHidesSiteThatDidNotImportTheApiSet(): void
    {
        if (!class_exists(ComponentFactory::class)) {
            self::markTestSkipped('The Integrations backend module is TYPO3 v14+ only.');
        }

        // A site without the tca_api site set has no tca_api.* settings, so the API is
        // inactive for it (mirrors TcaApiMiddleware's "!has(apiPrefix)" gate). It must NOT
        // be documented — otherwise the module invents docs for an endpoint that 404s.
        $withoutSet = $this->makeSite('plain', 'https://plain.example.org/', tcaApi: []);
        $siteFinder = $this->createMock(SiteFinder::class);
        $siteFinder->method('getAllSites')->willReturn(['plain' => $withoutSet]);

        $html = (string)$this->controllerWithSiteFinder($siteFinder)->indexAction($this->buildBackendRequest())->getBody();

        self::assertStringContainsString('No site has the TCA API enabled.', $html);
        self::assertStringNotContainsString('id="tca-api-swagger-ui"', $html);
    }

    public function testIndexActionHidesSiteWithApiExplicitlyDisabled(): void
    {
        if (!class_exists(ComponentFactory::class)) {
            self::markTestSkipped('The Integrations backend module is TYPO3 v14+ only.');
        }

        // The set is imported but tca_api.enabled = false, so the middleware serves nothing
        // for this site — the module must not document it either.
        $disabled = $this->makeSite('off', 'https://off.example.org/', ['apiPrefix' => '/_api/', 'enabled' => false]);
        $siteFinder = $this->createMock(SiteFinder::class);
        $siteFinder->method('getAllSites')->willReturn(['off' => $disabled]);

        $html = (string)$this->controllerWithSiteFinder($siteFinder)->indexAction($this->buildBackendRequest())->getBody();

        self::assertStringContainsString('No site has the TCA API enabled.', $html);
        self::assertStringNotContainsString('id="tca-api-swagger-ui"', $html);
    }

    public function testIndexActionRendersSiteSwitcherWhenMultipleSitesExist(): void
    {
        if (!class_exists(ComponentFactory::class)) {
            self::markTestSkipped('The Integrations backend module is TYPO3 v14+ only.');
        }

        // Drive the "count($sites) > 1" branch with two enabled sites, plus a disabled one
        // that must be filtered out of the switcher entirely.
        $main = $this->get(SiteFinder::class)->getSiteByIdentifier('main');
        $second = $this->makeSite('second', 'https://second.example.org/');
        $disabled = $this->makeSite('off', 'https://off.example.org/', ['apiPrefix' => '/_api/', 'enabled' => false]);
        $siteFinder = $this->createMock(SiteFinder::class);
        $siteFinder->method('getAllSites')->willReturn(['main' => $main, 'second' => $second, 'off' => $disabled]);

        $html = (string)$this->controllerWithSiteFinder($siteFinder)->indexAction($this->buildBackendRequest())->getBody();

        // Both enabled sites appear as items in the docheader site switcher dropdown …
        self::assertStringContainsString('>main<', $html);
        self::assertStringContainsString('>second<', $html);
        // … but the disabled site is not offered.
        self::assertStringNotContainsString('>off<', $html);
    }

    public function testDownloadActionFallsBackToRequestHostForHostlessSiteBase(): void
    {
        if (!class_exists(ComponentFactory::class)) {
            self::markTestSkipped('The Integrations backend module is TYPO3 v14+ only.');
        }

        // A site with a host-less base ("/") drives the "$base->getHost() === ''" branch in
        // resolveSiteBaseUri, which falls back to the current (backend) request's host.
        $hostless = $this->makeSite('hostless', '/');
        $siteFinder = $this->createMock(SiteFinder::class);
        $siteFinder->method('getAllSites')->willReturn(['hostless' => $hostless]);

        // A distinctive host+port proves the fallback fired: a host-full site base would be
        // used verbatim and never pick up "backend.example.org:8443".
        $request = new ServerRequest('http://backend.example.org:8443/typo3/module/integrations/tca-api/download');
        $response = $this->controllerWithSiteFinder($siteFinder)->downloadAction($request);

        self::assertSame(200, $response->getStatusCode());
        $spec = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('http://backend.example.org:8443', $spec['servers'][0]['url']);
    }

    public function testIndexActionOpenInNewTabLinkRespectsSiteBasePath(): void
    {
        if (!class_exists(ComponentFactory::class)) {
            self::markTestSkipped('The Integrations backend module is TYPO3 v14+ only.');
        }

        // Regression guard: a site served under a sub-path must produce an "open in new tab"
        // link (and servers URL) under that same path, not at the domain root. Previously the
        // origin-only base dropped "/bootstrap", so the link opened the root site's docs.
        $subPathSite = $this->makeSite('bootstrap', 'https://sites.example.org/bootstrap/');
        $siteFinder = $this->createMock(SiteFinder::class);
        $siteFinder->method('getAllSites')->willReturn(['bootstrap' => $subPathSite]);

        $html = (string)$this->controllerWithSiteFinder($siteFinder)->indexAction($this->buildBackendRequest())->getBody();

        // The open-in-new-tab button links under the site's own sub-path.
        self::assertStringContainsString('https://sites.example.org/bootstrap/_api/swagger-ui', $html);
        // And Try-it-out targets the same sub-path base, not the domain root.
        self::assertStringContainsString('"url":"https://sites.example.org/bootstrap"', $html);
        self::assertStringNotContainsString('"url":"https://sites.example.org"', $html);
    }

    public function testDownloadActionServerUrlRespectsSiteBasePath(): void
    {
        if (!class_exists(ComponentFactory::class)) {
            self::markTestSkipped('The Integrations backend module is TYPO3 v14+ only.');
        }

        $subPathSite = $this->makeSite('bootstrap', 'https://sites.example.org/bootstrap/');
        $siteFinder = $this->createMock(SiteFinder::class);
        $siteFinder->method('getAllSites')->willReturn(['bootstrap' => $subPathSite]);

        $request = (new ServerRequest('http://localhost/typo3/module/integrations/tca-api/download'))
            ->withQueryParams(['site' => 'bootstrap']);
        $response = $this->controllerWithSiteFinder($siteFinder)->downloadAction($request);

        $spec = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        // servers URL keeps the sub-path so "Try it out" hits the site's own endpoints.
        self::assertSame('https://sites.example.org/bootstrap', $spec['servers'][0]['url']);
    }

    public function testDownloadActionReturns404WhenNoSiteHasApiEnabled(): void
    {
        if (!class_exists(ComponentFactory::class)) {
            self::markTestSkipped('The Integrations backend module is TYPO3 v14+ only.');
        }

        // Only a disabled site exists → no downloadable spec, even though the site is
        // configured. Guards against the module leaking an openapi.json for a dead API.
        $disabled = $this->makeSite('off', 'https://off.example.org/', ['apiPrefix' => '/_api/', 'enabled' => false]);
        $siteFinder = $this->createMock(SiteFinder::class);
        $siteFinder->method('getAllSites')->willReturn(['off' => $disabled]);

        $request = (new ServerRequest('http://localhost/typo3/module/integrations/tca-api/download'))
            ->withQueryParams(['site' => 'off']);
        $response = $this->controllerWithSiteFinder($siteFinder)->downloadAction($request);

        self::assertSame(404, $response->getStatusCode());
    }

    /**
     * Build a synthetic Site. By default it carries a tca_api settings tree (so the API
     * counts as enabled); pass tcaApi: [] to model a site that never imported the tca_api
     * set, or tcaApi with 'enabled' => false to model an explicitly disabled API.
     *
     * @param array<string, mixed> $tcaApi
     */
    private function makeSite(string $identifier, string $base, array $tcaApi = ['apiPrefix' => '/_api/']): Site
    {
        $configuration = ['base' => $base];
        if ($tcaApi !== []) {
            $configuration['settings'] = ['tca_api' => $tcaApi];
        }

        return new Site($identifier, 1, $configuration);
    }

    /**
     * Invoke the container-built controller's indexAction directly, bypassing the
     * backend module routing and template wiring.
     */
    private function renderModule(): ResponseInterface
    {
        return $this->get(ApiDocumentationController::class)->indexAction($this->buildBackendRequest());
    }

    /**
     * Build a backend request carrying the attributes the ModuleTemplate render
     * pipeline relies on, and set up the admin backend user + language service.
     */
    private function buildBackendRequest(): ServerRequestInterface
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

        return $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));
    }

    /**
     * Rebuild the controller with all real collaborators from the container except
     * a caller-supplied SiteFinder, so tests can control what getAllSites() returns.
     */
    private function controllerWithSiteFinder(SiteFinder $siteFinder): ApiDocumentationController
    {
        return new ApiDocumentationController(
            $this->get(ModuleTemplateFactory::class),
            $this->get(ComponentFactory::class),
            $this->get(UriBuilder::class),
            $this->get(PageRenderer::class),
            $siteFinder,
            $this->get(OpenApiBuilder::class),
            $this->get(IconFactory::class),
            $this->get(ResponseFactoryInterface::class),
        );
    }
}
