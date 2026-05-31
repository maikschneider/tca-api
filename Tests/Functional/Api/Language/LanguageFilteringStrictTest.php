<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api\Language;

use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;

/**
 * Enforces strict language filtering where untranslated records are omitted.
 */
final class LanguageFilteringStrictTest extends ApiFunctionalTestCase
{
    protected array $pathsToLinkInTestInstance = [
        'typo3conf/ext/tca_api/Tests/Functional/Fixtures/Sites_MultiLanguage_Strict' => 'typo3conf/sites',
        'typo3conf/ext/tca_api/Tests/Functional/Fixtures/fileadmin/user_upload' => 'fileadmin/user_upload',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/sys_categories_multilang.csv');
    }

    /** Strict mode returns only records that have German translations. */
    public function testStrictModeReturnsOnlyGermanRecords(): void
    {
        $response = $this->executeApiRequest('/de/api/sys-categories');
        $body = $this->decodeResponseBody($response);

        self::assertCount(3, $body['hydra:member']);
    }

    /** Strict mode omits untranslated English records. */
    public function testStrictModeOmitsUntranslatedRecord(): void
    {
        $response = $this->executeApiRequest('/de/api/sys-categories');
        $body = $this->decodeResponseBody($response);

        foreach ($body['hydra:member'] as $member) {
            self::assertFalse(($member['title'] ?? '') === 'REST');
            self::assertFalse(str_ends_with((string)($member['@id'] ?? ''), '/104'));
        }
    }

    /** English requests are unaffected by German strict-mode filtering. */
    public function testEnglishRequestUnaffectedByStrictMode(): void
    {
        $response = $this->executeApiRequest('/api/sys-categories');
        $body = $this->decodeResponseBody($response);

        self::assertCount(4, $body['hydra:member']);
    }

    /** Records flagged sys_language_uid = -1 ("all languages") survive strict mode without translation. */
    public function testAllLanguagesRecordSurvivesStrictMode(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/sys_categories_alllang.csv');

        $response = $this->executeApiRequest('/de/api/sys-categories');
        $body = $this->decodeResponseBody($response);

        $allLangMember = null;
        foreach ($body['hydra:member'] as $m) {
            if (str_ends_with((string)($m['@id'] ?? ''), '/199')) {
                $allLangMember = $m;
                break;
            }
        }

        self::assertNotNull($allLangMember);
        self::assertSame('All-Lang', $allLangMember['title']);
    }
}
