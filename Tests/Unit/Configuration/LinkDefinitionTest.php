<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Unit\Configuration;

use MaikSchneider\TcaApi\Configuration\LinkDefinition;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class LinkDefinitionTest extends UnitTestCase
{
    #[Test]
    public function theConstructorIsPrivateSoEveryInstancePassesTheScopeCheck(): void
    {
        self::assertTrue((new \ReflectionMethod(LinkDefinition::class, '__construct'))->isPrivate());
    }

    #[Test]
    public function fromArrayRejectsAnEmptyScope(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        LinkDefinition::fromArray([]);
    }

    #[Test]
    public function foldersMustBeAList(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('link.folders');

        LinkDefinition::fromArray(['folders' => ['storage' => '1:/downloads/']]);
    }

    #[Test]
    #[DataProvider('invalidFolderEntryProvider')]
    public function aFolderEntryMustBeAFalIdentifier(mixed $entry): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('link.folders');

        LinkDefinition::fromArray(['folders' => [$entry]]);
    }

    public static function invalidFolderEntryProvider(): \Generator
    {
        yield 'no storage prefix' => ['/downloads/'];
        yield 'storage is not numeric' => ['fileadmin:/downloads/'];
        yield 'not a string' => [1];
    }

    #[Test]
    #[DataProvider('invalidCheckProvider')]
    public function checkMustBeAClassAndMethodPair(mixed $check): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('link.check');

        LinkDefinition::fromArray(['check' => $check]);
    }

    public static function invalidCheckProvider(): \Generator
    {
        yield 'not an array' => ['SomeClass::someMethod'];
        yield 'method missing' => [['SomeClass']];
        yield 'class is not a string' => [[1, 'someMethod']];
    }

    #[Test]
    public function checkAloneIsAValidScope(): void
    {
        $definition = LinkDefinition::fromArray(['check' => ['SomeClass', 'someMethod']]);

        self::assertTrue($definition->coversFolder(1, '/anywhere/file.pdf'));
    }

    #[Test]
    public function subFoldersAreCovered(): void
    {
        $definition = LinkDefinition::fromArray(['folders' => ['1:/downloads']]);

        self::assertTrue($definition->coversFolder(1, '/downloads/2026/report.pdf'));
        self::assertFalse($definition->coversFolder(1, '/protected/report.pdf'));
        self::assertFalse($definition->coversFolder(2, '/downloads/report.pdf'));
    }

    #[Test]
    public function aSiblingFolderWithTheSamePrefixIsNotCovered(): void
    {
        $definition = LinkDefinition::fromArray(['folders' => ['1:/downloads/']]);

        self::assertFalse($definition->coversFolder(1, '/downloads-private/report.pdf'));
    }
}
