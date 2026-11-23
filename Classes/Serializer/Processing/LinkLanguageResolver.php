<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Serializer\Processing;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Type\Bitmask\PageTranslationVisibility;

/**
 * Picks the language a generated page link uses: the request language, mapped
 * to the site of the target page.
 */
final readonly class LinkLanguageResolver
{
    public function __construct(private ConnectionPool $connectionPool)
    {
    }

    /**
     * The request of a column processor's context, else the global request for
     * callers that serialize without passing one.
     *
     * @param array<string, mixed> $context
     */
    public static function contextRequest(array $context): ?ServerRequestInterface
    {
        $request = $context['request'] ?? $GLOBALS['TYPO3_REQUEST'] ?? null;

        return $request instanceof ServerRequestInterface ? $request : null;
    }

    /**
     * Whether the page can be linked in the language, as the frontend decides:
     * an untranslated page is not served in a language with fallback type
     * "strict", nor when the page is set to hide if not translated.
     */
    public function isPageAvailable(int $pageId, SiteLanguage $language): bool
    {
        if ($language->getLanguageId() <= 0) {
            return true;
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $translated = $queryBuilder
            ->count('uid')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('l10n_parent', $queryBuilder->createNamedParameter($pageId, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter($language->getLanguageId(), Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchOne();
        if ((int)$translated > 0) {
            return true;
        }
        if ($language->getFallbackType() === 'strict') {
            return false;
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $l18nConfiguration = $queryBuilder
            ->select('l18n_cfg')
            ->from('pages')
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($pageId, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchOne();

        return !(new PageTranslationVisibility((int)$l18nConfiguration))->shouldHideTranslationIfNoTranslatedRecordExists();
    }

    /**
     * Returns the target site's language with the id of the request language,
     * else the first of its fallback languages the target site has, else the
     * target site's default language. Null when the request has no language.
     */
    public function resolve(Site $targetSite, ?ServerRequestInterface $request): ?SiteLanguage
    {
        $requestLanguage = $this->requestLanguage($request);
        if ($requestLanguage === null) {
            return null;
        }

        foreach ([$requestLanguage->getLanguageId(), ...$requestLanguage->getFallbackLanguageIds()] as $languageId) {
            try {
                return $targetSite->getLanguageById($languageId);
            } catch (\InvalidArgumentException) {
            }
        }

        return $targetSite->getDefaultLanguage();
    }

    public function requestLanguage(?ServerRequestInterface $request): ?SiteLanguage
    {
        if ($request === null) {
            return null;
        }
        // The API dispatcher's language honours the X-Locale header; the
        // frontend SiteResolver's attribute is the fallback.
        foreach (['tca_api.language', 'language'] as $attribute) {
            $language = $request->getAttribute($attribute);
            if ($language instanceof SiteLanguage) {
                return $language;
            }
        }
        return null;
    }
}
