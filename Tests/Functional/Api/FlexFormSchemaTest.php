<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api;

use MaikSchneider\TcaApi\Enum\AccessRole;
use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * A record type with its own FlexForm data structure gets a typed schema for the
 * decoded type=flex value.
 *
 * The test_record_types fixture extension adds the CType `tca_api_flexform`
 * (header, pi_flexform) whose data structure has the sheets sDEF (title: input,
 * limit: number) and display (showDate: check, links: section of link
 * containers with an input url).
 *
 * uid=9100 is a tca_api_flexform element with a title and one link.
 */
final class FlexFormSchemaTest extends ApiFunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'tca_api',
        __DIR__ . '/../Fixtures/Extensions/test_record_types',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        // Not a CSV fixture: ddev init-typo3 imports those without the fixture extension's CType.
        GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tt_content')
            ->insert('tt_content', [
                'uid'         => 9100,
                'pid'         => 1,
                'CType'       => 'tca_api_flexform',
                'header'      => 'FlexForm element',
                'pi_flexform' => '<?xml version="1.0" encoding="utf-8" standalone="yes" ?><T3FlexForms><data>'
                    . '<sheet index="sDEF"><language index="lDEF"><field index="settings.title"><value index="vDEF">Hello</value></field></language></sheet>'
                    . '<sheet index="display"><language index="lDEF"><field index="settings.links"><el index="el">'
                    . '<field index="1"><container index="link"><el index="el"><field index="url"><value index="vDEF">https://typo3.org</value></field></el></container></field>'
                    . '</el></field></language></sheet>'
                    . '</data></T3FlexForms>',
            ]);

        $this->registerResource('flex-content', self::config('flex-content', 'FlexContent', ['recordTypes' => ['tca_api_flexform', 'header']]));
        $this->registerResource('all-content', self::config('all-content', 'AllContent', []));
    }

    private static function config(string $resourceName, string $resourceType, array $general): array
    {
        return [
            'general' => [
                'table'        => 'tt_content',
                'resourceName' => $resourceName,
                'resourceType' => $resourceType,
                'storagePid'   => 1,
            ] + $general,
            'security' => [
                'list' => AccessRole::PUBLIC,
                'show' => AccessRole::PUBLIC,
            ],
        ];
    }

    public function testATypesFlexFormDataStructureTypesTheDecodedValue(): void
    {
        $flexForm = $this->schemas()['FlexContentTcaApiFlexformRead']['properties']['pi_flexform'];
        $sheets = $flexForm['properties']['data']['properties'];
        $sDef = $sheets['sDEF']['properties']['lDEF']['properties'];
        $display = $sheets['display']['properties']['lDEF']['properties'];

        self::assertSame('object', $flexForm['type']);
        self::assertSame(['type' => 'string'], $sDef['settings.title']['properties']['vDEF']);
        self::assertSame(['type' => ['integer', 'string']], $sDef['settings.limit']['properties']['vDEF']);
        self::assertSame(['type' => ['integer', 'string']], $display['settings.showDate']['properties']['vDEF']);

        $container = $display['settings.links']['properties']['el']['additionalProperties']['properties']['link'];
        self::assertSame(['type' => 'string'], $container['properties']['el']['properties']['url']['properties']['vDEF']);
    }

    public function testTheSerializedValueHasTheDescribedShape(): void
    {
        $flexForm = $this->decodeResponseBody($this->executeApiRequest('/_api/flex-content/9100'))['pi_flexform'];

        self::assertSame('Hello', $flexForm['data']['sDEF']['lDEF']['settings.title']['vDEF']);
        self::assertSame('https://typo3.org', $flexForm['data']['display']['lDEF']['settings.links']['el'][1]['link']['el']['url']['vDEF']);
    }

    public function testAValueThatDependsOnAnUnknownRecordTypeStaysAnObject(): void
    {
        $schemas = $this->schemas();

        self::assertSame(['type' => 'object'], $schemas['AllContentRead']['properties']['pi_flexform']);
        self::assertArrayNotHasKey('pi_flexform', $schemas['FlexContentHeaderRead']['properties']);
    }

    private function schemas(): array
    {
        return $this->decodeResponseBody($this->executeApiRequest('/_api/openapi.json'))['components']['schemas'];
    }
}
