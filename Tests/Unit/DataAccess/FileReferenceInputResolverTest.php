<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Unit\DataAccess;

use MaikSchneider\TcaApi\Configuration\ApiDefinition;
use MaikSchneider\TcaApi\DataAccess\FileReferenceInputResolver;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Paths that never reach the database. Everything that resolves a file uid is
 * covered by Tests/Functional/Api/Write/WriteLinkExistingFileTest.php.
 */
final class FileReferenceInputResolverTest extends UnitTestCase
{
    private const TABLE = 'tx_tcaapitest_file';

    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['TCA'][self::TABLE]['columns'] = [
            'title' => ['config' => ['type' => 'input']],
            'photo' => ['config' => ['type' => 'file']],
        ];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TCA'][self::TABLE]);
        parent::tearDown();
    }

    #[Test]
    public function nullDetachesWithoutTouchingTheDatabase(): void
    {
        $resolved = $this->subject()->resolve(['photo' => null], $this->definition());

        self::assertSame(['photo' => []], $resolved->references);
        self::assertSame([], $resolved->violations);
        self::assertArrayNotHasKey('photo', $resolved->body);
    }

    #[Test]
    public function aNonWritableColumnIsLeftToTheColumnFilter(): void
    {
        // Explicit mode without 'create' in the column's groups: the resolver
        // must not claim the value, or the filter never gets to reject it.
        $definition = $this->definition(['groups' => ['list', 'show']]);

        $resolved = $this->subject()->resolve(['photo' => 12], $definition);

        self::assertSame([], $resolved->references);
        self::assertSame([], $resolved->violations);
        self::assertSame(['photo' => 12], $resolved->body);
    }

    private function subject(): FileReferenceInputResolver
    {
        return new FileReferenceInputResolver($this->createMock(ConnectionPool::class));
    }

    /** @param array<string, mixed> $photoColumn */
    private function definition(array $photoColumn = []): ApiDefinition
    {
        return ApiDefinition::fromArray([
            'general' => [
                'table'        => self::TABLE,
                'resourceName' => 'file-things',
                'resourceType' => 'FileThing',
                'operations'   => ['list', 'show', 'create'],
            ],
            'columns' => [
                'photo' => $photoColumn + ['link' => ['folders' => ['1:/downloads/']]],
            ],
        ]);
    }
}
