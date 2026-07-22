<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Controller;

use MaikSchneider\TcaApi\Dispatcher\RequestContext;
use MaikSchneider\TcaApi\OpenApi\OpenApiBuilder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
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
 * Swagger UI. This deliberately bypasses the `tca_api.enabled` /
 * `tca_api.swaggerUiEnabled` / `tca_api.openApiExposed` site settings that gate
 * the public frontend endpoints: the backend documentation is always available
 * to admins, regardless of how (or whether) the API is exposed publicly.
 */
#[AsController]
#[Autoconfigure(public: true)]
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
        private IconFactory $iconFactory,
    ) {
    }

    public function indexAction(ServerRequestInterface $request): ResponseInterface
    {
        $view = $this->moduleTemplateFactory->create($request);
        $view->setTitle($this->getLanguageService()->sL(self::LLL . ':mlang_tabs_tab'));
        $view->assign('domId', self::DOM_ID);

        // Restore the "Integrations" submodule switcher (Reactions / Webhooks / …).
        $view->makeDocHeaderModuleMenu();

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

        $context = $this->createRequestContext($site, $request);
        $specification = $this->openApiBuilder->build($context);
        $specification['servers'] = [['url' => $context->baseUrl]];
        $this->registerSwaggerAssets($specification);

        $this->addOpenInNewTabButton($view, $context->baseUrl . $context->prefix . '/swagger-ui');

        $view->assign('hasSites', true);
        $view->assign('siteIdentifier', $site->getIdentifier());
        return $view->renderResponse('ApiDocumentation/Index');
    }

    /**
     * Build a RequestContext for a site, anchored at the site base so it resolves
     * baseUrl + apiPrefix exactly as a real frontend request would. The resulting
     * context feeds both the OpenAPI build (via {@see OpenApiBuilder}, reusing the
     * exact same builder that backs the public `openapi.json` endpoint, without any
     * access gate) and the "open in new tab" frontend URL.
     *
     * A site may have a host-less base (e.g. "/") — fall back to the current backend
     * host so the resulting absolute URLs stay usable.
     */
    private function createRequestContext(Site $site, ServerRequestInterface $request): RequestContext
    {
        $base = $site->getBase();
        if ($base->getHost() === '') {
            $backendUri = $request->getUri();
            $base = $base
                ->withScheme($backendUri->getScheme())
                ->withHost($backendUri->getHost())
                ->withPort($backendUri->getPort());
        }

        return new RequestContext($site->getSettings(), new ServerRequest($base));
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
