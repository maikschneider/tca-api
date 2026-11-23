<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Serializer\Processing;

use MaikSchneider\TcaApi\Configuration\ColumnDefinition;
use TYPO3\CMS\Core\LinkHandling\LinkService;
use TYPO3\CMS\Core\Routing\RouterInterface;
use TYPO3\CMS\Core\Site\SiteFinder;

final class TypoLinkProcessor implements ColumnProcessorInterface
{
    public function __construct(
        private readonly LinkService $linkService,
        private readonly SiteFinder $siteFinder,
        private readonly LinkLanguageResolver $linkLanguageResolver,
    ) {
    }

    public function process(mixed $value, ColumnDefinition $config, array $context): mixed
    {
        if (!\is_string($value) || $value === '') {
            return null;
        }

        try {
            $linkDetails = $this->linkService->resolve($value);
        } catch (\Exception) {
            return $value;
        }

        return match ($linkDetails['type'] ?? LinkService::TYPE_UNKNOWN) {
            LinkService::TYPE_URL       => $linkDetails['url'] ?? $value,
            LinkService::TYPE_EMAIL     => !empty($linkDetails['email']) ? 'mailto:' . $linkDetails['email'] : null,
            LinkService::TYPE_TELEPHONE => !empty($linkDetails['telephone']) ? 'tel:' . $linkDetails['telephone'] : null,
            LinkService::TYPE_PAGE      => $this->resolvePageUrl($linkDetails, $context),
            default                     => $value,
        };
    }

    private function resolvePageUrl(array $linkDetails, array $context): ?string
    {
        $pageId = (int)($linkDetails['pageuid'] ?? 0);
        if ($pageId <= 0) {
            return null;
        }

        try {
            $queryParams = [];
            if (!empty($linkDetails['parameters'])) {
                parse_str(ltrim($linkDetails['parameters'], '&?'), $queryParams);
            }

            $site = $this->siteFinder->getSiteByPageId($pageId);

            // A language set in the link wins ("_language" over "L", as in the
            // frontend typolink); otherwise the link follows the request language.
            $linkLanguage = $queryParams['_language'] ?? $queryParams['L'] ?? null;
            unset($queryParams['L']);
            if ($linkLanguage === null) {
                $linkLanguage = $this->linkLanguageResolver->resolve($site, LinkLanguageResolver::contextRequest($context));
                if ($linkLanguage !== null && !$this->linkLanguageResolver->isPageAvailable($pageId, $linkLanguage)) {
                    return null;
                }
            }
            $queryParams['_language'] = $linkLanguage;

            $uri  = $site->getRouter()->generateUri(
                $pageId,
                $queryParams,
                $linkDetails['fragment'] ?? '',
                RouterInterface::ABSOLUTE_URL,
            );

            return (string)$uri;
        } catch (\Exception) {
            return null;
        }
    }
}
