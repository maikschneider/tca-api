<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api\Language;

use MaikSchneider\TcaApi\Configuration\ColumnDefinition;
use MaikSchneider\TcaApi\Serializer\Processing\RouteEnhancerProcessor;
use MaikSchneider\TcaApi\Serializer\Processing\TypoLinkProcessor;
use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Page links follow the language of the API request.
 *
 * Site "main" (http://localhost/) has English (0), German (1, /de/) and Swiss
 * German (2, /de-ch/, falls back to 1 and 0). Site "other"
 * (http://other.localhost/) has English and German only.
 *
 *   Page 900 /about    (main),  DE /ueber-uns
 *   Page 902 /imprint  (main),  no DE, hidden if not translated
 *   Page 903 /team     (main),  no DE
 *   Page 911 /contact  (other), DE /kontakt
 */
final class TypoLinkLanguageTest extends ApiFunctionalTestCase
{
    protected array $pathsToLinkInTestInstance = [
        'typo3conf/ext/tca_api/Tests/Functional/Fixtures/Sites_LinkLanguage' => 'typo3conf/sites',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages_link_language.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/articles_link_language.csv');

        $this->registerResource('link-articles', [
            'general' => [
                'table' => 'tx_myext_domain_model_article',
                'resourceName' => 'link-articles',
                'resourceType' => 'Article',
                'operations' => ['list', 'show'],
            ],
            'columns' => [
                'article_url' => ['groups' => ['list', 'show']],
            ],
            'virtualProperties' => [
                'contactUrl' => [
                    'groups' => ['show'],
                    'processor' => RouteEnhancerProcessor::class,
                    'route' => ['pid' => 911],
                ],
            ],
        ]);
    }

    public static function linkProvider(): \Generator
    {
        yield 'default language, same site' => ['', 7800, 'http://localhost/about'];
        yield 'German, same site' => ['/de', 7800, 'http://localhost/de/ueber-uns'];
        yield 'default language, other site' => ['', 7801, 'http://other.localhost/contact'];
        yield 'German, other site' => ['/de', 7801, 'http://other.localhost/de/kontakt'];
        yield 'language missing on other site, its fallback applies' => ['/de-ch', 7801, 'http://other.localhost/de/kontakt'];
        yield '_language in the link wins' => ['/de', 7802, 'http://localhost/about'];
        yield 'L=0 in the link wins' => ['/de', 7803, 'http://localhost/about'];
        yield 'L=1 in the link wins' => ['', 7804, 'http://localhost/de/ueber-uns'];
        yield 'untranslated page, default language' => ['', 7805, 'http://localhost/imprint'];
        yield 'untranslated page hidden if not translated is not linked' => ['/de', 7805, null];
        yield 'untranslated page, German falls back' => ['/de', 7806, 'http://localhost/de/team'];
    }

    #[Test]
    #[DataProvider('linkProvider')]
    public function pageLinkFollowsRequestLanguage(string $languageBase, int $uid, ?string $expected): void
    {
        $body = $this->decodeResponseBody($this->executeApiRequest($languageBase . '/api/link-articles/' . $uid));

        self::assertSame($expected, $body['article_url']);
    }

    #[Test]
    public function pageLinkFollowsTheXLocaleHeader(): void
    {
        $body = $this->decodeResponseBody($this->executeApiRequestWithHeaders('/api/link-articles/7800', ['X-Locale' => '1']));

        self::assertSame('http://localhost/de/ueber-uns', $body['article_url']);
    }

    #[Test]
    public function routeToOtherSiteUsesThatSitesLanguage(): void
    {
        $body = $this->decodeResponseBody($this->executeApiRequest('/de-ch/api/link-articles/7800'));

        self::assertSame('http://other.localhost/de/kontakt', $body['contactUrl']);
    }

    #[Test]
    public function processorReadsLanguageFromContextRequest(): void
    {
        unset($GLOBALS['TYPO3_REQUEST']);
        $german  = $this->get(SiteFinder::class)->getSiteByIdentifier('main')->getLanguageById(1);
        $request = (new ServerRequest('http://localhost/de/api/link-articles/7800'))->withAttribute('tca_api.language', $german);

        $url = $this->get(TypoLinkProcessor::class)->process(
            't3://page?uid=900',
            new ColumnDefinition(groups: null),
            ['serializedRow' => [], 'rawRow' => [], 'request' => $request],
        );

        self::assertSame('http://localhost/de/ueber-uns', $url);
    }

    #[Test]
    public function routeProcessorReadsLanguageFromContextRequest(): void
    {
        unset($GLOBALS['TYPO3_REQUEST']);
        $german  = $this->get(SiteFinder::class)->getSiteByIdentifier('main')->getLanguageById(1);
        $request = (new ServerRequest('http://localhost/de/api/link-articles/7800'))->withAttribute('tca_api.language', $german);

        $url = $this->get(RouteEnhancerProcessor::class)->process(
            null,
            ColumnDefinition::fromArray(['route' => ['pid' => 911]]),
            ['serializedRow' => [], 'rawRow' => [], 'request' => $request],
        );

        self::assertSame('http://other.localhost/de/kontakt', $url);
    }
}
