<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api\Language;

use MaikSchneider\TcaApi\Enum\AccessRole;
use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;

/**
 * Enforces fallback-mode language filtering for translated and untranslated records.
 */
final class LanguageFilteringTest extends ApiFunctionalTestCase
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

    /** Fallback mode overlays German translations when they exist. */
    public function testFallbackModeReturnsGermanWhenTranslationExists(): void
    {
        $response = $this->executeApiRequest('/de/api/sys-categories');
        $body = $this->decodeResponseBody($response);
        $member = $this->findMemberByUid($body, 101);

        self::assertTrue(\is_array($member));
        self::assertSame('PHP DE', $member['title']);
    }

    /** Fallback mode keeps English originals when translations are missing. */
    public function testFallbackModeFallsBackToEnglishWhenTranslationMissing(): void
    {
        $response = $this->executeApiRequest('/de/api/sys-categories');
        $body = $this->decodeResponseBody($response);
        $member = $this->findMemberByUid($body, 104);

        self::assertTrue($member !== null);
        self::assertSame('REST', $member['title']);
    }

    /** English requests expose only English original records. */
    public function testEnglishRequestReturnsOnlyEnglishOriginals(): void
    {
        $response = $this->executeApiRequest('/api/sys-categories');
        $body = $this->decodeResponseBody($response);

        self::assertCount(4, $body['hydra:member']);
        foreach ($body['hydra:member'] as $member) {
            $id = (string)($member['@id'] ?? '');
            self::assertFalse(str_ends_with($id, '/151'));
            self::assertFalse(str_ends_with($id, '/152'));
            self::assertFalse(str_ends_with($id, '/153'));
        }
    }

    /** Language-agnostic resources ignore the resolved locale. */
    public function testLanguageAgnosticResourceIgnoresLocale(): void
    {
        $this->registerResource('sys-categories-all', [
            'general' => [
                'table' => 'sys_category',
                'resourceName' => 'sys-categories-all',
                'resourceType' => 'SysCategoryAll',
                'itemsPerPage' => 50,
                'language' => ['mode' => 'ignore'],
            ],
            'columns' => [
                'title' => ['groups' => ['list', 'show']],
            ],
            'security' => [
                'list' => AccessRole::PUBLIC,
                'show' => AccessRole::PUBLIC,
            ],
        ]);

        $englishResponse = $this->executeApiRequest('/api/sys-categories-all');
        $germanResponse = $this->executeApiRequest('/de/api/sys-categories-all');
        $englishBody = $this->decodeResponseBody($englishResponse);
        $germanBody = $this->decodeResponseBody($germanResponse);

        self::assertCount(7, $englishBody['hydra:member']);
        self::assertCount(7, $germanBody['hydra:member']);
    }

    /** Opt-out language config returns originals and translations together. */
    public function testOptOutConfigReturnsAllLanguageVariants(): void
    {
        $this->registerResource('sys-categories-variants', [
            'general' => [
                'table' => 'sys_category',
                'resourceName' => 'sys-categories-variants',
                'resourceType' => 'SysCategoryVariants',
                'itemsPerPage' => 50,
                'language' => ['mode' => 'ignore'],
            ],
            'columns' => [
                'title' => ['groups' => ['list', 'show']],
            ],
            'security' => [
                'list' => AccessRole::PUBLIC,
                'show' => AccessRole::PUBLIC,
            ],
        ]);

        $response = $this->executeApiRequest('/api/sys-categories-variants');
        $body = $this->decodeResponseBody($response);
        $englishMember = $this->findMemberByUid($body, 101);
        $germanMember = $this->findMemberByUid($body, 151);

        self::assertTrue(\is_array($englishMember));
        self::assertTrue(\is_array($germanMember));
        self::assertSame('PHP', $englishMember['title']);
        self::assertSame('PHP DE', $germanMember['title']);
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
