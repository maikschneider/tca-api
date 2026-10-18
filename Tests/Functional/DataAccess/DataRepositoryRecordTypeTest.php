<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\DataAccess;

use MaikSchneider\TcaApi\Configuration\ApiDefinition;
use MaikSchneider\TcaApi\DataAccess\DataRepository;
use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * The record-type constraint binds its values by the type column's storage type.
 * PostgreSQL rejects both `int = varchar` and `varchar = int`, so numeric-looking
 * record types in a varchar column (e.g. tx_news `type` = '0', '1', '2') must
 * still be bound as strings.
 */
final class DataRepositoryRecordTypeTest extends ApiFunctionalTestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['TCA']['tt_content']['types']['1']);
        parent::tearDown();
    }

    #[Test]
    public function integerTypeColumnIsBoundAsIntegers(): void
    {
        $types = $this->boundParameterTypes(ApiDefinition::fromArray([
            'general' => [
                'table'        => 'pages',
                'resourceName' => 'standard-pages',
                'resourceType' => 'StandardPage',
                'recordTypes'  => ['1', '4'],
            ],
        ]));

        self::assertSame([Connection::PARAM_INT_ARRAY], $types);
    }

    #[Test]
    public function varcharTypeColumnWithNumericTypesIsBoundAsStrings(): void
    {
        $GLOBALS['TCA']['tt_content']['types']['1'] = ['showitem' => 'CType,header'];

        $types = $this->boundParameterTypes(ApiDefinition::fromArray([
            'general' => [
                'table'        => 'tt_content',
                'resourceName' => 'numeric-content',
                'resourceType' => 'NumericContent',
                'recordTypes'  => ['1'],
            ],
        ]));

        self::assertSame([Connection::PARAM_STR_ARRAY], $types);
    }

    /** @return list<mixed> */
    private function boundParameterTypes(ApiDefinition $config): array
    {
        $qb = $this->get(ConnectionPool::class)->getQueryBuilderForTable($config->table);
        $qb->select('uid')->from($config->table);

        (new \ReflectionMethod(DataRepository::class, 'applyRecordTypeConstraint'))
            ->invoke($this->get(DataRepository::class), $qb, $config);

        return array_values($qb->getParameterTypes());
    }
}
