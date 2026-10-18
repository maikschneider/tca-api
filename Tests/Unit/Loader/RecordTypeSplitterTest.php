<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Unit\Loader;

use MaikSchneider\TcaApi\Exception\InvalidApiDefinitionException;
use MaikSchneider\TcaApi\Loader\RecordTypeSplitter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RecordTypeSplitterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['TCA']['tx_test'] = [
            'ctrl' => ['type' => 'record_type'],
            'columns' => [
                'record_type' => ['config' => ['type' => 'select', 'items' => [
                    ['label' => 'News article', 'value' => 'news'],
                    ['label' => 'Event', 'value' => 'event_date'],
                ]]],
            ],
            'types' => ['news' => ['showitem' => ''], 'event_date' => ['showitem' => ''], 'with space' => ['showitem' => '']],
        ];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TCA']['tx_test']);
        parent::tearDown();
    }

    private static function config(array $recordTypes, array $extra = []): array
    {
        return array_replace_recursive([
            'general' => [
                'table'        => 'tx_test',
                'resourceName' => 'items',
                'resourceType' => 'Item',
                'recordTypes'  => $recordTypes,
            ],
        ], $extra);
    }

    #[Test]
    public function configsWithoutSplitStayUnchanged(): void
    {
        $configs = ['items' => self::config(['news']), 'other' => self::config(['include' => ['news'], 'schema' => 'table'])];

        self::assertSame($configs, RecordTypeSplitter::expand($configs));
    }

    #[Test]
    public function splitGeneratesOneResourcePerTypeAndKeepsTheBase(): void
    {
        $expanded = RecordTypeSplitter::expand(['items' => self::config(['include' => ['news', 'event_date'], 'split' => true, 'schema' => 'table'])]);

        self::assertSame(['items-news', 'items-event_date', 'items'], array_keys($expanded));
        self::assertSame(
            ['table' => 'tx_test', 'resourceName' => 'items-event_date', 'recordTypes' => ['include' => ['event_date'], 'schema' => 'table'], 'resourceType' => 'ItemsEventDate', 'group' => ['name' => 'ItemsEventDate', 'description' => 'Event']],
            $expanded['items-event_date']['general'],
        );
        self::assertTrue($expanded['items']['general']['recordTypes']['split']);
    }

    #[Test]
    public function aTypesEntryIsMergedOverItsGeneratedResourceOnly(): void
    {
        $expanded = RecordTypeSplitter::expand(['items' => self::config(['include' => ['news', 'event_date'], 'split' => true], [
            'security' => ['list' => 'public'],
            'types'    => ['news' => ['security' => ['list' => 'frontend_user'], 'general' => ['resourceName' => 'articles', 'resourceType' => 'Article']]],
        ])]);

        self::assertSame(['articles', 'items-event_date', 'items'], array_keys($expanded));
        self::assertSame('frontend_user', $expanded['articles']['security']['list']);
        self::assertSame('Article', $expanded['articles']['general']['resourceType']);
        self::assertSame('public', $expanded['items-event_date']['security']['list']);
        self::assertArrayNotHasKey('types', $expanded['items']);
        self::assertArrayNotHasKey('types', $expanded['articles']);
    }

    #[Test]
    public function namePatternAndKeepBaseFalseApply(): void
    {
        $expanded = RecordTypeSplitter::expand(['items' => self::config(['include' => ['news'], 'split' => true, 'namePattern' => '{type}-entries', 'keepBase' => false])]);

        self::assertSame(['news-entries'], array_keys($expanded));
        self::assertSame('NewsEntries', $expanded['news-entries']['general']['resourceType']);
    }

    #[Test]
    public function aConfiguredGroupIsKept(): void
    {
        $expanded = RecordTypeSplitter::expand(['items' => self::config(['include' => ['news'], 'split' => true], ['general' => ['group' => 'Editorial']])]);

        self::assertSame('Editorial', $expanded['items-news']['general']['group']);
    }

    #[Test]
    public function aGeneratedNameUsedByAnotherResourceThrows(): void
    {
        $this->expectException(InvalidApiDefinitionException::class);
        $this->expectExceptionMessage('"items-news" is already used by another resource');

        RecordTypeSplitter::expand([
            'items'      => self::config(['include' => ['news'], 'split' => true]),
            'items-news' => self::config(['news'], ['general' => ['resourceName' => 'items-news']]),
        ]);
    }

    #[Test]
    public function aGeneratedNameUsedByAnotherSplitThrows(): void
    {
        $this->expectException(InvalidApiDefinitionException::class);
        $this->expectExceptionMessage('is already used by a resource generated from "items"');

        RecordTypeSplitter::expand([
            'items'  => self::config(['include' => ['news'], 'split' => true, 'namePattern' => 'shared-{type}']),
            'others' => self::config(['include' => ['news'], 'split' => true, 'namePattern' => 'shared-{type}'], ['general' => ['resourceName' => 'others']]),
        ]);
    }

    #[Test]
    public function aGeneratedNameWithCharactersUnsafeInAUrlThrows(): void
    {
        $this->expectException(InvalidApiDefinitionException::class);
        $this->expectExceptionMessage('"items-with space" may only contain letters, digits');

        RecordTypeSplitter::expand(['items' => self::config(['include' => ['with space'], 'split' => true])]);
    }

    #[Test]
    public function typesWithoutSplitThrows(): void
    {
        $this->expectException(InvalidApiDefinitionException::class);
        $this->expectExceptionMessage('"types" requires general.recordTypes.split = true');

        RecordTypeSplitter::expand(['items' => self::config(['news'], ['types' => ['news' => []]])]);
    }

    #[Test]
    public function typesForATypeThatIsNotIncludedThrows(): void
    {
        $this->expectException(InvalidApiDefinitionException::class);
        $this->expectExceptionMessage('lists record type(s) "event_date"');

        RecordTypeSplitter::expand(['items' => self::config(['include' => ['news'], 'split' => true], ['types' => ['event_date' => []]])]);
    }

    #[Test]
    public function aNamePatternWithoutTypeThrows(): void
    {
        $this->expectException(InvalidApiDefinitionException::class);
        $this->expectExceptionMessage('namePattern must be a string containing "{type}"');

        RecordTypeSplitter::expand(['items' => self::config(['include' => ['news'], 'split' => true, 'namePattern' => 'items'])]);
    }

    #[Test]
    public function aKeepBaseThatIsNoBooleanThrows(): void
    {
        $this->expectException(InvalidApiDefinitionException::class);
        $this->expectExceptionMessage('keepBase must be a boolean');

        RecordTypeSplitter::expand(['items' => self::config(['include' => ['news'], 'split' => true, 'keepBase' => 'no'])]);
    }

    #[Test]
    public function typesThatAreNoArrayThrow(): void
    {
        $this->expectException(InvalidApiDefinitionException::class);
        $this->expectExceptionMessage('"types" must be an array keyed by record type');

        RecordTypeSplitter::expand(['items' => self::config(['include' => ['news'], 'split' => true], ['types' => 'news'])]);
    }

    #[Test]
    public function aTypesEntryThatIsNoArrayThrows(): void
    {
        $this->expectException(InvalidApiDefinitionException::class);
        $this->expectExceptionMessage('"types.news" must be an array');

        RecordTypeSplitter::expand(['items' => self::config(['include' => ['news'], 'split' => true], ['types' => ['news' => 'x']])]);
    }

    #[Test]
    public function aTypesEntryReplacesListsInsteadOfMergingThemByIndex(): void
    {
        $expanded = RecordTypeSplitter::expand(['items' => self::config(['include' => ['news'], 'split' => true], [
            'general'  => ['operations' => ['list', 'show', 'create']],
            'security' => ['show' => ['frontend_group', [1, 2]]],
            'types'    => ['news' => [
                'general'  => ['operations' => ['list']],
                'security' => ['show' => ['frontend_group', [5]]],
            ]],
        ])]);

        self::assertSame(['list'], $expanded['items-news']['general']['operations']);
        self::assertSame(['frontend_group', [5]], $expanded['items-news']['security']['show']);
    }

    #[Test]
    public function aResourceNameOverrideAlsoNamesTheResourceTypeAndGroup(): void
    {
        $expanded = RecordTypeSplitter::expand(['items' => self::config(['include' => ['news'], 'split' => true], [
            'types' => ['news' => ['general' => ['resourceName' => 'articles']]],
        ])]);

        self::assertSame('Articles', $expanded['articles']['general']['resourceType']);
        self::assertSame(['name' => 'Articles', 'description' => 'News article'], $expanded['articles']['general']['group']);
    }

    #[Test]
    public function aResourceTypeOverrideNamesTheGroup(): void
    {
        $expanded = RecordTypeSplitter::expand(['items' => self::config(['include' => ['news'], 'split' => true], [
            'types' => ['news' => ['general' => ['resourceType' => 'Article']]],
        ])]);

        self::assertSame('Article', $expanded['items-news']['general']['resourceType']);
        self::assertSame('Article', $expanded['items-news']['general']['group']['name']);
    }

    #[Test]
    public function twoTypesOverriddenToTheSameNameThrow(): void
    {
        $this->expectException(InvalidApiDefinitionException::class);
        $this->expectExceptionMessage('"articles" is already used by a resource generated from "items"');

        RecordTypeSplitter::expand(['items' => self::config(['include' => ['news', 'event_date'], 'split' => true], [
            'types' => [
                'news'       => ['general' => ['resourceName' => 'articles']],
                'event_date' => ['general' => ['resourceName' => 'articles']],
            ],
        ])]);
    }

    #[Test]
    public function aSplitThatIsNoBooleanThrows(): void
    {
        $this->expectException(InvalidApiDefinitionException::class);
        $this->expectExceptionMessage('split must be a boolean');

        RecordTypeSplitter::expand(['items' => self::config(['include' => ['news'], 'split' => 1])]);
    }

    #[Test]
    public function invalidTypeItemsYieldNoLabels(): void
    {
        $GLOBALS['TCA']['tx_test']['columns']['record_type']['config']['items'] = [['value' => 'news'], 'invalid', ['label' => '', 'value' => 'event_date']];
        $news = RecordTypeSplitter::expand(['items' => self::config(['include' => ['news'], 'split' => true])])['items-news'];
        self::assertArrayNotHasKey('group', $news['general']);

        $GLOBALS['TCA']['tx_test']['columns']['record_type']['config']['items'] = 'invalid';
        $news = RecordTypeSplitter::expand(['items' => self::config(['include' => ['news'], 'split' => true])])['items-news'];
        self::assertArrayNotHasKey('group', $news['general']);
    }
}
