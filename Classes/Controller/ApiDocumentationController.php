<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Controller;

use MaikSchneider\TcaApi\Dispatcher\RequestContext;
use MaikSchneider\TcaApi\OpenApi\OpenApiBuilder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\Components\ComponentFactory;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\PathUtility;

/**
 * Backend module rendering the TCA API OpenAPI documentation (Swagger UI)
 * beneath the TYPO3 v14 "Integrations" main module.
 *
 * The specification is built server-side, per site, and embedded inline into
 * Swagger UI. This deliberately bypasses the `tca_api.enabled` /
 * `tca_api.swaggerUiEnabled` / `tca_api.openApiExposed` site settings that gate
 * the public frontend endpoints: the backend documentation is always available
 * to admins, regardless of how (or whether) the API is exposed publicly.
 */
#[AsController]
final readonly class ApiDocumentationController
{
    private const LLL = 'LLL:EXT:tca_api/Resources/Private/Language/locallang_mod.xlf';
    private const MODULE_IDENTIFIER = 'tca_api_documentation';
    private const DOM_ID = 'tca-api-swagger-ui';

    public function __construct(
        private ModuleTemplateFactory $moduleTemplateFactory,
        private ComponentFactory $componentFactory,
        private UriBuilder $uriBuilder,
        private PageRenderer $pageRenderer,
        private SiteFinder $siteFinder,
        private OpenApiBuilder $openApiBuilder,
    ) {
    }

    public function indexAction(ServerRequestInterface $request): ResponseInterface
    {
        $view = $this->moduleTemplateFactory->create($request);
        $view->setTitle($this->getLanguageService()->sL(self::LLL . ':mlang_tabs_tab'));
        $view->assign('domId', self::DOM_ID);

        $sites = $this->siteFinder->getAllSites();
        if ($sites === []) {
            $view->assign('hasSites', false);
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

        $this->registerSwaggerAssets($this->buildSpecification($site, $request));

        $view->assign('hasSites', true);
        $view->assign('siteIdentifier', $site->getIdentifier());
        return $view->renderResponse('ApiDocumentation/Index');
    }

    /**
     * Build the OpenAPI specification for a site, reusing the exact same builder
     * that backs the public `openapi.json` endpoint — but without any access gate.
     *
     * @return array<string, mixed>
     */
    private function buildSpecification(Site $site, ServerRequestInterface $request): array
    {
        // A synthetic request anchored at the site base lets RequestContext resolve
        // baseUrl + apiPrefix exactly as a real frontend request would. A site may
        // have a host-less base (e.g. "/") — fall back to the current backend host
        // so the resulting "servers" URL stays absolute.
        $base = $site->getBase();
        if ($base->getHost() === '') {
            $backendUri = $request->getUri();
            $base = $base
                ->withScheme($backendUri->getScheme())
                ->withHost($backendUri->getHost())
                ->withPort($backendUri->getPort());
        }

        $context = new RequestContext($site->getSettings(), new ServerRequest($base));
        $specification = $this->openApiBuilder->build($context);

        // Point Swagger UI's "Try it out" at the real API base for this site.
        $specification['servers'] = [['url' => $context->baseUrl . $context->prefix]];

        return $specification;
    }

    /**
     * Register Swagger UI assets against the backend PageRenderer.
     *
     * The bundle is added as a header file (so the `SwaggerUIBundle` global is
     * defined before the footer init runs) and the init block is added as inline
     * footer code with `csp: true`, which makes PageRenderer emit it with the
     * backend Content-Security-Policy nonce. No custom CSP mutation is required:
     * the default backend policy already allows same-origin scripts, nonce'd
     * inline scripts, `style-src 'unsafe-inline'` (Swagger injects styles) and
     * `img-src data:` (Swagger icons).
     *
     * @param array<string, mixed> $specification
     */
    private function registerSwaggerAssets(array $specification): void
    {
        $this->pageRenderer->addCssFile(
            PathUtility::getPublicResourceWebPath('EXT:tca_api/Resources/Public/SwaggerUI/swagger-ui.css'),
        );
        $this->pageRenderer->addJsFile(
            PathUtility::getPublicResourceWebPath('EXT:tca_api/Resources/Public/SwaggerUI/swagger-ui-bundle.js'),
        );

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

    /**
     * @param array<string, Site> $sites
     */
    private function addSiteMenu(ModuleTemplate $view, array $sites, string $selectedIdentifier): void
    {
        $menu = $this->componentFactory->createMenu();
        $menu->setIdentifier('tca-api-site');
        $menu->setLabel($this->getLanguageService()->sL(self::LLL . ':module.site.dropdown'));

        foreach ($sites as $identifier => $site) {
            $menuItem = $this->componentFactory->createMenuItem()
                ->setHref((string)$this->uriBuilder->buildUriFromRoute(self::MODULE_IDENTIFIER, ['site' => $identifier]))
                ->setTitle($site->getIdentifier());
            if ($identifier === $selectedIdentifier) {
                $menuItem->setActive(true);
            }
            $menu->addMenuItem($menuItem);
        }

        $view->getDocHeaderComponent()->getMenuRegistry()->addMenu($menu);
    }

    private function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }
}
