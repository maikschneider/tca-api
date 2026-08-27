<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Unit\Configuration;

use MaikSchneider\TcaApi\Configuration\LinkDefinition;
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
