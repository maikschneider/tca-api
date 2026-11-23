<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api\Language;

use MaikSchneider\TcaApi\Enum\AccessRole;
use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;
use MaikSchneider\TcaApi\Tests\Functional\QueryCounting\CountingDriverMiddleware;
use MaikSchneider\TcaApi\Tests\Functional\QueryCounting\QueryCounter;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * A translation owns its file references: DataHandler copies them on localize and
 * stores them against the translation's uid. All records are created and localized
 * through DataHandler, as an editor would.
 */
final class LanguageFileReferenceTest extends ApiFunctionalTestCase
{
    protected array $pathsToLinkInTestInstance = [
        'typo3conf/ext/tca_api/Tests/Functional/Fixtures/Sites_MultiLanguage' => 'typo3conf/sites',
    ];

    protected array $configurationToUseInTestInstance = [
        'DB' => [
            'Connections' => [
                'Default' => [
                    'driverMiddlewares' => [
                        'tca-api/query-counter' => ['target' => CountingDriverMiddleware::class],
                    ],
                ],
            ],
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/sys_file.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/be_users.csv');

        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)
            ->createFromUserPreferences($this->setUpBackendUser(2));

        $this->registerResource('content', [
            'general' => [
                'table'        => 'tt_content',
                'resourceName' => 'content',
                'resourceType' => 'Content',
            ],
            'columns' => [
                'header'  => ['groups' => ['list', 'show']],
                'image'   => ['groups' => ['list', 'show']],
                'records' => ['groups' => ['list', 'show'], 'embed' => true, 'resourceName' => 'content'],
            ],
            'virtualProperties' => ['teaser' => ['column' => 'image', 'groups' => ['list', 'show']]],
            'security' => ['list' => AccessRole::PUBLIC, 'show' => AccessRole::PUBLIC],
            'order'    => ['allowed' => ['uid'], 'default' => ['uid' => 'asc']],
        ]);

        $this->registerResource('pages', [
            'general' => [
                'table'        => 'pages',
                'resourceName' => 'pages',
                'resourceType' => 'Page',
            ],
            'columns'  => ['title' => ['groups' => ['list', 'show']], 'media' => ['groups' => ['list', 'show']]],
            'security' => ['list' => AccessRole::PUBLIC, 'show' => AccessRole::PUBLIC],
            'order'    => ['allowed' => ['uid'], 'default' => ['uid' => 'asc']],
        ]);
    }

    #[Test]
    public function aTranslatedContentElementReturnsItsOwnImage(): void
    {
        $uid = $this->createContentWithImage('Image EN', 'Title EN');
        $this->translateReference('tt_content', 'image', $this->localize('tt_content', $uid), 'Title DE');

        $default    = $this->decodeResponseBody($this->executeApiRequest('/api/content/' . $uid));
        $translated = $this->decodeResponseBody($this->executeApiRequest('/de/api/content/' . $uid));

        self::assertSame('Title EN', $default['image'][0]['metadata']['title']);
        self::assertSame('Title DE', $translated['image'][0]['metadata']['title']);
        self::assertSame('Title DE', $translated['teaser'][0]['metadata']['title']);
        self::assertSame('profile.jpg', $translated['image'][0]['name']);
    }

    #[Test]
    public function aTranslatedContentElementKeepsItsDefaultLanguageUid(): void
    {
        $uid = $this->createContentWithImage('Image EN', 'Title EN');
        $this->translateReference('tt_content', 'image', $this->localize('tt_content', $uid), 'Title DE');

        $default    = $this->decodeResponseBody($this->executeApiRequest('/api/content/' . $uid));
        $translated = $this->decodeResponseBody($this->executeApiRequest('/de/api/content/' . $uid));

        self::assertSame($uid, $translated['uid']);
        self::assertSame($default['@id'], $translated['@id']);
    }

    #[Test]
    public function theCollectionReturnsTheTranslatedImage(): void
    {
        $uid = $this->createContentWithImage('Image EN', 'Title EN');
        $this->translateReference('tt_content', 'image', $this->localize('tt_content', $uid), 'Title DE');

        $members = $this->decodeResponseBody($this->executeApiRequest('/de/api/content'))['hydra:member'];

        self::assertSame([$uid], array_column($members, 'uid'));
        self::assertSame('Title DE', $members[0]['image'][0]['metadata']['title']);
    }

    #[Test]
    public function translatedImagesDoNotAddQueriesPerRecord(): void
    {
        for ($i = 1; $i <= 12; $i++) {
            $uid = $this->createContentWithImage('Image EN ' . $i, 'Title EN ' . $i);
            $this->translateReference('tt_content', 'image', $this->localize('tt_content', $uid), 'Title DE ' . $i);
        }
        $path = '/de/api/content';

        // Warm the per-process caches on records neither measured page touches.
        $this->executeApiRequest($path, ['itemsPerPage' => 2, 'page' => 2]);

        QueryCounter::start();
        $this->executeApiRequest($path, ['itemsPerPage' => 2, 'page' => 1]);
        $small = QueryCounter::stop();

        QueryCounter::start();
        $members = $this->decodeResponseBody($this->executeApiRequest($path, ['itemsPerPage' => 6, 'page' => 2]))['hydra:member'];
        $large = QueryCounter::stop();

        self::assertSame(0, intdiv($large - $small, 4));
        self::assertSame(['Title DE 7', 'Title DE 12'], [$members[0]['image'][0]['metadata']['title'], $members[5]['image'][0]['metadata']['title']]);
    }

    #[Test]
    public function anEmbeddedTranslatedRecordReturnsItsOwnImage(): void
    {
        $uid = $this->createContentWithImage('Image EN', 'Title EN');
        $this->translateReference('tt_content', 'image', $this->localize('tt_content', $uid), 'Title DE');
        $shortcut = $this->process(['tt_content' => ['NEW1' => [
            'pid'     => 1,
            'CType'   => 'shortcut',
            'header'  => 'Shortcut EN',
            'records' => 'tt_content_' . $uid,
        ]]])->substNEWwithIDs['NEW1'];

        $body = $this->decodeResponseBody($this->executeApiRequest('/de/api/content/' . $shortcut));

        self::assertSame($uid, $body['records'][0]['uid']);
        self::assertSame('Title DE', $body['records'][0]['image'][0]['metadata']['title']);
    }

    #[Test]
    public function anUntranslatedContentElementFallsBackToTheDefaultImage(): void
    {
        $uid = $this->createContentWithImage('Image EN', 'Title EN');

        $body = $this->decodeResponseBody($this->executeApiRequest('/de/api/content/' . $uid));

        self::assertSame('Title EN', $body['image'][0]['metadata']['title']);
    }

    #[Test]
    public function aTranslatedPageReturnsItsOwnMedia(): void
    {
        $this->addPageMedia('Title EN');
        $this->translateReference('pages', 'media', $this->localize('pages', 1), 'Title DE');

        $default    = $this->decodeResponseBody($this->executeApiRequest('/api/pages/1'));
        $translated = $this->decodeResponseBody($this->executeApiRequest('/de/api/pages/1'));

        self::assertSame('Title EN', $default['media'][0]['metadata']['title']);
        self::assertSame('Title DE', $translated['media'][0]['metadata']['title']);
        self::assertSame(1, $translated['uid']);
    }

    #[Test]
    public function aPageTranslationKeepsTheDefaultFiles(): void
    {
        $this->addPageMedia('Title EN');
        $this->localize('pages', 1);

        $default    = $this->decodeResponseBody($this->executeApiRequest('/api/pages/1'));
        $translated = $this->decodeResponseBody($this->executeApiRequest('/de/api/pages/1'));

        // Localizing copies the references, so the translation shows the same files.
        self::assertCount(1, $translated['media']);
        self::assertSame($default['media'][0]['uid'], $translated['media'][0]['uid']);
        self::assertSame($default['media'][0]['publicUrl'], $translated['media'][0]['publicUrl']);
    }

    #[Test]
    public function aTranslationWithoutReferencesReturnsNoImage(): void
    {
        $uid           = $this->createContentWithImage('Image EN', 'Title EN');
        $translatedUid = $this->localize('tt_content', $uid);
        $this->process([], ['sys_file_reference' => [$this->translatedReferenceUid('tt_content', 'image', $translatedUid) => ['delete' => 1]]]);

        $body = $this->decodeResponseBody($this->executeApiRequest('/de/api/content/' . $uid));

        // As in the frontend: the editor removed the image from the translation.
        self::assertSame([], $body['image']);
    }

    #[Test]
    public function aHiddenReferenceOfTheTranslationIsNotReplacedByTheDefaultImage(): void
    {
        $uid           = $this->createContentWithImage('Image EN', 'Title EN');
        $translatedUid = $this->localize('tt_content', $uid);
        $this->process(['sys_file_reference' => [$this->translatedReferenceUid('tt_content', 'image', $translatedUid) => ['hidden' => 1]]]);

        $body = $this->decodeResponseBody($this->executeApiRequest('/de/api/content/' . $uid));

        self::assertSame([], $body['image']);
    }

    #[Test]
    public function aRecordForAllLanguagesReturnsItsOwnImage(): void
    {
        $uid = $this->createContentWithImage('Image all', 'Title all', -1);

        $body = $this->decodeResponseBody($this->executeApiRequest('/de/api/content/' . $uid));

        self::assertSame('Title all', $body['image'][0]['metadata']['title']);
    }

    private function createContentWithImage(string $header, string $title, int $languageUid = 0): int
    {
        return $this->process([
            'tt_content'         => ['NEW1' => ['pid' => 1, 'CType' => 'image', 'header' => $header, 'image' => 'NEW2', 'sys_language_uid' => $languageUid]],
            'sys_file_reference' => ['NEW2' => ['pid' => 1, 'uid_local' => 1, 'title' => $title]],
        ])->substNEWwithIDs['NEW1'];
    }

    private function addPageMedia(string $title): void
    {
        $this->process([
            'pages'              => [1 => ['media' => 'NEW1']],
            'sys_file_reference' => ['NEW1' => ['pid' => 1, 'uid_local' => 1, 'title' => $title]],
        ]);
    }

    /** Localizes a record to German, publishes the translation (hidden by hideAtCopy) and returns its uid. */
    private function localize(string $table, int $uid): int
    {
        $translatedUid = $this->process([], [$table => [$uid => ['localize' => 1]]])->copyMappingArray_merged[$table][$uid] ?? null;
        self::assertIsInt($translatedUid);
        self::assertNotSame($uid, $translatedUid);
        $this->process([$table => [$translatedUid => ['hidden' => 0]]]);

        return $translatedUid;
    }

    /** Edits the title of the file reference a translation owns. */
    private function translateReference(string $table, string $field, int $translatedUid, string $title): void
    {
        $this->process(['sys_file_reference' => [$this->translatedReferenceUid($table, $field, $translatedUid) => ['title' => $title]]]);
    }

    private function translatedReferenceUid(string $table, string $field, int $translatedUid): int
    {
        $qb = $this->getConnectionPool()->getQueryBuilderForTable('sys_file_reference');
        $referenceUids = $qb->select('uid')
            ->from('sys_file_reference')
            ->where(
                $qb->expr()->eq('tablenames', $qb->createNamedParameter($table)),
                $qb->expr()->eq('fieldname', $qb->createNamedParameter($field)),
                $qb->expr()->eq('uid_foreign', $qb->createNamedParameter($translatedUid, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchFirstColumn();
        self::assertCount(1, $referenceUids);

        return (int)$referenceUids[0];
    }

    private function process(array $data, array $commands = []): DataHandler
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start($data, $commands);
        $dataHandler->process_datamap();
        $dataHandler->process_cmdmap();
        self::assertSame([], $dataHandler->errorLog);

        return $dataHandler;
    }
}
