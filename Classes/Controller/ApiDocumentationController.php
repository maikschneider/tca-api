<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Controller;

use MaikSchneider\TcaApi\Dispatcher\RequestContext;
use MaikSchneider\TcaApi\OpenApi\OpenApiBuilder;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\Components\ComponentFactory;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Backend module rendering the TCA API OpenAPI documentation (Swagger UI)
 * beneath the TYPO3 v14 "Integrations" main module.
 *
 * The specification is built server-side, per site, and embedded inline into
 * Swagger UI. Only sites where the API is actually active are shown — mirroring
 * {@see \MaikSchneider\TcaApi\Middleware\TcaApiMiddleware}, that means the site
 * imports the tca_api site set (so `tca_api.apiPrefix` exists) and does not set
 * `tca_api.enabled = false`. A site without the API produces no endpoints, so
 * documenting it would be misleading.
 *
 * It deliberately bypasses only the *public access gates* — `tca_api.swaggerUiEnabled`
 * and `tca_api.openApiExposed` — which govern how the spec/UI are exposed to
 * anonymous frontend visitors: the backend documentation stays available to admins
 * regardless of how (or whether) the API is exposed publicly.
 */
#[AsController]
#[Autoconfigure(public: true)]
final readonly class ApiDocumentationController
{
    private const LLL = 'LLL:EXT:tca_api/Resources/Private/Language/locallang_mod.xlf';
    private const MODULE_IDENTIFIER = 'tca_api_documentation';
    // Named sub-routes register as "{moduleIdentifier}.{routeName}" (see ModuleRegistry).
    private const DOWNLOAD_ROUTE = self::MODULE_IDENTIFIER . '.download';
    private const DOM_ID = 'tca-api-swagger-ui';

    public function __construct(
        private ModuleTemplateFactory $moduleTemplateFactory,
        private ComponentFactory $componentFactory,
        private UriBuilder $uriBuilder,
        private PageRenderer $pageRenderer,
        private SiteFinder $siteFinder,
        private OpenApiBuilder $openApiBuilder,
        private IconFactory $iconFactory,
        private ResponseFactoryInterface $responseFactory,
    ) {
    }

    public function indexAction(ServerRequestInterface $request): ResponseInterface
    {
        $view = $this->moduleTemplateFactory->create($request);
        $view->setTitle($this->getLanguageService()->sL(self::LLL . ':mlang_tabs_tab'));
        $view->assign('domId', self::DOM_ID);

        // Restore the "Integrations" submodule switcher (Reactions / Webhooks / …).
        $view->makeDocHeaderModuleMenu();

        $sites = $this->enabledSites();
        if ($sites === []) {
            $view->assign('hasEnabledSite', false);
            return $view->renderResponse('ApiDocumentation/Index');
        }

        $moduleData = $request->getAttribute('moduleData');
        $siteIdentifiers = array_keys($sites);
        $moduleData->clean('site', $siteIdentifiers);
        $selectedIdentifier = (string)$moduleData->get('site');
        if (!isset($sites[$selectedIdentifier])) {
            $selectedIdentifier = $siteIdentifiers[0];
        }
        $site = $sites[$selectedIdentifier];

        if (count($sites) > 1) {
            $this->addSiteMenu($view, $sites, $selectedIdentifier);
            $view->setTitle(
                $this->getLanguageService()->sL(self::LLL . ':mlang_tabs_tab'),
                $site->getIdentifier(),
            );
        }

        $baseUri = $this->resolveSiteBaseUri($site, $request);
        $context = new RequestContext($site->getSettings(), new ServerRequest($baseUri));
        // The full site base — origin *and* path (e.g. "https://example.com/bootstrap") —
        // so a site served under a sub-path resolves to its own docs. RequestContext::baseUrl
        // is origin-only and would drop the path, pointing every sub-path site at the root.
        $siteBaseUrl = rtrim((string)$baseUri, '/');

        $specification = $this->openApiBuilder->build($context);
        $specification['servers'] = [['url' => $siteBaseUrl]];
        $this->registerSwaggerAssets($specification);

        $this->addDownloadButton($view, $site->getIdentifier());
        $this->addOpenInNewTabButton($view, $siteBaseUrl . $context->prefix . '/swagger-ui');

        $view->assign('hasEnabledSite', true);
        $view->assign('siteIdentifier', $site->getIdentifier());
        return $view->renderResponse('ApiDocumentation/Index');
    }

    /**
     * Serve the OpenAPI specification for the selected site as a downloadable
     * `openapi.json` file. Like {@see indexAction()}, this builds the spec via
     * {@see OpenApiBuilder} without consulting the public access gates, so the
     * download is available to admins regardless of how the API is exposed publicly.
     * It is limited to sites where the API is actually enabled ({@see enabledSites()});
     * requesting a site without it yields 404.
     *
     * The target site is taken from the `site` query parameter (set by the docheader
     * download button to the site currently shown), falling back to the first
     * enabled site.
     */
    public function downloadAction(ServerRequestInterface $request): ResponseInterface
    {
        $sites = $this->enabledSites();
        if ($sites === []) {
            return $this->responseFactory->createResponse(404);
        }

        $requested = (string)($request->getQueryParams()['site'] ?? '');
        $site = $sites[$requested] ?? reset($sites);

        $baseUri = $this->resolveSiteBaseUri($site, $request);
        $context = new RequestContext($site->getSettings(), new ServerRequest($baseUri));
        $specification = $this->openApiBuilder->build($context);
        $specification['servers'] = [['url' => rtrim((string)$baseUri, '/')]];

        $response = $this->responseFactory->createResponse(200)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Content-Disposition', 'attachment; filename="openapi.json"');
        $response->getBody()->write((string)json_encode($specification, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $response;
    }

    /**
     * All configured sites where the TCA API is actually active, keyed by identifier.
     *
     * @return array<string, Site>
     */
    private function enabledSites(): array
    {
        return array_filter($this->siteFinder->getAllSites(), $this->isApiEnabledForSite(...));
    }

    /**
     * Whether the TCA API is active for a site, mirroring the two gates
     * {@see \MaikSchneider\TcaApi\Middleware\TcaApiMiddleware} applies: the site must
     * import the tca_api site set (so `tca_api.apiPrefix` is defined) and must not set
     * `tca_api.enabled = false`. Sites failing either gate expose no API and are hidden
     * from the module — the public-only `swaggerUiEnabled` / `openApiExposed` gates are
     * intentionally NOT consulted here.
     */
    private function isApiEnabledForSite(Site $site): bool
    {
        $settings = $site->getSettings();

        return $settings->has('tca_api.apiPrefix') && (bool)$settings->get('tca_api.enabled', true);
    }

    /**
     * Resolve the absolute base URI of a site — origin *and* path — as the anchor for
     * both the per-site OpenAPI build and the frontend URLs (servers, "open in new tab").
     * Keeping the path is essential: a site served under a sub-path (base
     * "https://example.com/bootstrap") must resolve to its own docs, not the root site's.
     *
     * A site may have a host-less base (e.g. "/") — fall back to the current backend
     * host so the resulting absolute URLs stay usable.
     */
    private function resolveSiteBaseUri(Site $site, ServerRequestInterface $request): UriInterface
    {
        $base = $site->getBase();
        if ($base->getHost() === '') {
            $backendUri = $request->getUri();
            $base = $base
                ->withScheme($backendUri->getScheme())
                ->withHost($backendUri->getHost())
                ->withPort($backendUri->getPort());
        }

        return $base;
    }

    /**
     * Register Swagger UI assets against the backend PageRenderer.
     *
     * Four static files are added the same way (via {@see PageRenderer::addCssFile()} /
     * {@see PageRenderer::addJsFile()}): the vendored Swagger bundle plus two small
     * backend-only override files that live next to it under Resources/Public/SwaggerUI/:
     *
     * - `swagger-ui-backend.css` — corrects the bundle's unconditional dark-mode title
     *   colour (invisible on the light backend) and hides the redundant "Servers" box.
     * - `swagger-ui-backend.js` — mirrors the backend's effective colour scheme onto a
     *   `dark-mode` class so Swagger's own (otherwise `html.dark-mode`-gated) dark theme
     *   activates. The backend never uses that class itself, so it only flips Swagger.
     *
     * Only the bootstrap call is emitted inline (it carries the per-site spec, which is
     * dynamic and cannot be a static file). It is added as inline footer code with
     * `csp: true`, so PageRenderer emits it with the backend CSP nonce; the static JS
     * bundle is a header file, so `SwaggerUIBundle` is defined before the footer init runs.
     *
     * @param array<string, mixed> $specification
     */
    private function registerSwaggerAssets(array $specification): void
    {
        $this->pageRenderer->addCssFile('EXT:tca_api/Resources/Public/SwaggerUI/swagger-ui.css');
        $this->pageRenderer->addCssFile('EXT:tca_api/Resources/Public/SwaggerUI/swagger-ui-backend.css');
        $this->pageRenderer->addJsFile('EXT:tca_api/Resources/Public/SwaggerUI/swagger-ui-bundle.js');
        $this->pageRenderer->addJsFile('EXT:tca_api/Resources/Public/SwaggerUI/swagger-ui-backend.js');

        // JSON_HEX_TAG neutralises "</script>", making the spec safe to inline.
        $specJson = (string)json_encode(
            $specification,
            JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES,
        );

        $domId = self::DOM_ID;
        $init = <<<JS
            SwaggerUIBundle({
                spec: {$specJson},
                dom_id: '#{$domId}',
                presets: [SwaggerUIBundle.presets.apis],
                layout: 'BaseLayout',
                deepLinking: true
            });
            JS;

        $this->pageRenderer->addJsFooterInlineCode('tca-api-swagger-ui', $init, csp: true);
    }

    private function addOpenInNewTabButton(ModuleTemplate $view, string $url): void
    {
        $button = $this->componentFactory->createLinkButton()
            ->setHref($url)
            ->setTitle($this->getLanguageService()->sL(self::LLL . ':module.openInNewTab'))
            ->setShowLabelText(true)
            ->setIcon($this->iconFactory->getIcon('actions-window-open', IconSize::SMALL))
            ->setAttributes(['target' => '_blank', 'rel' => 'noopener noreferrer']);

        $view->getDocHeaderComponent()->getButtonBar()->addButton($button, ButtonBar::BUTTON_POSITION_RIGHT);
    }

    /**
     * Add the "Download openapi.json" button, linking to the module's download
     * sub-route for the currently selected site.
     */
    private function addDownloadButton(ModuleTemplate $view, string $selectedIdentifier): void
    {
        $href = (string)$this->uriBuilder->buildUriFromRoute(self::DOWNLOAD_ROUTE, ['site' => $selectedIdentifier]);
        $button = $this->componentFactory->createLinkButton()
            ->setHref($href)
            ->setTitle($this->getLanguageService()->sL(self::LLL . ':module.download'))
            ->setShowLabelText(true)
            ->setIcon($this->iconFactory->getIcon('actions-download', IconSize::SMALL));

        $view->getDocHeaderComponent()->getButtonBar()->addButton($button, ButtonBar::BUTTON_POSITION_RIGHT);
    }

    /**
     * Add the site switcher as its own docheader dropdown button.
     *
     * @param array<string, Site> $sites
     */
    private function addSiteMenu(ModuleTemplate $view, array $sites, string $selectedIdentifier): void
    {
        $dropdown = $this->componentFactory->createDropDownButton()
            ->setLabel($this->getLanguageService()->sL(self::LLL . ':module.site.dropdown'))
            ->setShowLabelText(true)
            ->setShowActiveLabelText(true)
            ->setIcon($this->iconFactory->getIcon('actions-globe', IconSize::SMALL));

        foreach ($sites as $identifier => $site) {
            $item = $this->componentFactory->createDropDownRadio()
                ->setHref((string)$this->uriBuilder->buildUriFromRoute(self::MODULE_IDENTIFIER, ['site' => $identifier]))
                ->setLabel($site->getIdentifier())
                ->setActive($identifier === $selectedIdentifier);
            $dropdown->addItem($item);
        }

        $view->getDocHeaderComponent()->getButtonBar()->addButton($dropdown, ButtonBar::BUTTON_POSITION_LEFT, 2);
    }

    private function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }
}
