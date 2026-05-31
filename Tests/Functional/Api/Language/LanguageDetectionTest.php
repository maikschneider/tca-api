<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api\Language;

use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;

/**
 * Enforces language detection from site URL segments and X-Locale overrides.
 */
final class LanguageDetectionTest extends ApiFunctionalTestCase
{
    protected array $pathsToLinkInTestInstance = [
        'typo3conf/ext/tca_api/Tests/Functional/Fixtures/Sites_MultiLanguage' => 'typo3conf/sites',
        'typo3conf/ext/tca_api/Tests/Functional/Fixtures/fileadmin/user_upload' => 'fileadmin/user_upload',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/sys_categories_multilang.csv');
    }

    /** Root API requests resolve to the English site language. */
    public function testRootPathReturnsEnglishCategories(): void
    {
        $response = $this->executeApiRequest('/api/sys-categories');
        $body = $this->decodeResponseBody($response);
        $member = $this->findMemberByUid($body, 101);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue(\is_array($member));
        self::assertSame('PHP', $member['title']);
    }

    /** German URL segments resolve to TYPO3 language id 1. */
    public function testGermanUrlSegmentResolvesToLanguageId1(): void
    {
        $response = $this->executeApiRequest('/de/api/sys-categories');
        $body = $this->decodeResponseBody($response);
        $member = $this->findMemberByUid($body, 101);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue(\is_array($member));
        self::assertSame('PHP DE', $member['title']);
    }

    /** X-Locale overrides the locale inferred from the URL. */
    public function testXLocaleHeaderOverridesUrlLanguage(): void
    {
        $response = $this->executeApiRequestWithHeaders('/api/sys-categories', ['X-Locale' => '1']);
        $body = $this->decodeResponseBody($response);
        $member = $this->findMemberByUid($body, 101);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue(\is_array($member));
        self::assertSame('PHP DE', $member['title']);
    }

    /** Unknown X-Locale ids return a 400 hydra:Error naming the requested value and the available enabled language ids. */
    public function testUnknownLanguageIdReturns400WithAvailableLanguages(): void
    {
        $response = $this->executeApiRequestWithHeaders('/api/sys-categories', ['X-Locale' => '99']);
        $body = $this->decodeResponseBody($response);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('hydra:Error', $body['@type']);
        self::assertSame('Invalid language', $body['hydra:title']);
        self::assertArrayHasKey('hydra:description', $body);
        self::assertStringContainsString('99', $body['hydra:description']);
        self::assertStringContainsString('0', $body['hydra:description']);
        self::assertStringContainsString('1', $body['hydra:description']);
    }

    /** Non-integer X-Locale headers are rejected with a 400 response. */
    public function testNonIntegerXLocaleHeaderReturns400(): void
    {
        $response = $this->executeApiRequestWithHeaders('/api/sys-categories', ['X-Locale' => 'de']);

        self::assertSame(400, $response->getStatusCode());
    }

    /** Disabled site languages are rejected with a 400 response. */
    public function testDisabledLanguageReturns400(): void
    {
        $response = $this->executeApiRequestWithHeaders('/api/sys-categories', ['X-Locale' => '2']);

        self::assertSame(400, $response->getStatusCode());
    }

    /** Content-Language reflects the resolved response locale. */
    public function testContentLanguageHeaderReflectsResolvedLocale(): void
    {
        $response = $this->executeApiRequest('/de/api/sys-categories');

        self::assertStringContainsString('de', $response->getHeaderLine('Content-Language'));
    }

    /** Vary advertises that X-Locale participates in response negotiation. */
    public function testVaryHeaderIncludesXLocale(): void
    {
        $response = $this->executeApiRequest('/api/sys-categories');

        self::assertStringContainsString('X-Locale', $response->getHeaderLine('Vary'));
    }

    private function findMemberByUid(array $body, int $uid): ?array
    {
        foreach ($body['hydra:member'] ?? [] as $m) {
            // Hydra @id is typically "/api/sys-categories/<uid>".
            if (str_ends_with((string)($m['@id'] ?? ''), '/' . $uid)) {
                return $m;
            }
        }
        return null;
    }
}
