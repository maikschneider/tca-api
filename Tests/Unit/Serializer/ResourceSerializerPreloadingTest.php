<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Unit\Serializer;

use MaikSchneider\TcaApi\Cache\CacheTagCollector;
use MaikSchneider\TcaApi\Configuration\ApiDefinition;
use MaikSchneider\TcaApi\Configuration\ColumnDefinition;
use MaikSchneider\TcaApi\Serializer\FileFieldSerializer;
use MaikSchneider\TcaApi\Serializer\Processing\ColumnProcessorInterface;
use MaikSchneider\TcaApi\Serializer\Processing\PreloadingProcessorInterface;
use MaikSchneider\TcaApi\Serializer\Processing\ProcessorGuard;
use MaikSchneider\TcaApi\Serializer\RelationSerializer;
use MaikSchneider\TcaApi\Serializer\ResourceSerializer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Schema\TcaSchema;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;

final class ResourceSerializerPreloadingTest extends TestCase
{
    #[Test]
    public function failedPreloadIsNotCached(): void
    {
        $schema = $this->createMock(TcaSchema::class);
        $schema->method('hasField')->willReturn(false);

        $schemaFactory = $this->createMock(TcaSchemaFactory::class);
        $schemaFactory->method('get')->willReturn($schema);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error');

        $serializer = new ResourceSerializer(
            $schemaFactory,
            new CacheTagCollector(),
            (new \ReflectionClass(FileFieldSerializer::class))->newInstanceWithoutConstructor(),
            (new \ReflectionClass(RelationSerializer::class))->newInstanceWithoutConstructor(),
            new ProcessorGuard($logger),
        );
        $config = ApiDefinition::fromArray([
            'general' => [
                'table'        => 'tx_test',
                'resourceName' => 'tests',
                'resourceType' => 'Test',
            ],
            'columns' => [
                'missing' => ['groups' => ['list']],
            ],
            'virtualProperties' => [
                'processed' => [
                    'groups'    => ['list'],
                    'processor' => FailingPreloadingProcessor::class,
                ],
            ],
        ]);

        $result = $serializer->serializeCollection(
            [['uid' => 1]],
            $config,
            '/_api/tests',
            operation: 'list',
        );

        // 'prepared' would mean the instance whose prepare() threw was cached and
        // reused for the row — the exact leak this guards against.
        self::assertSame('unprepared', $result[0]['processed']);
    }

    #[Test]
    public function thePreparedProcessorIsReusedForTheRowsItPreloaded(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('error');

        $result = $this->serializeWith(SucceedingPreloadingProcessor::class, $logger);

        // A fresh instance would answer 'unprepared', so this pins the cache hit.
        self::assertSame('prepared', $result[0]['processed']);
    }

    #[Test]
    public function aProcessorThatCannotBeBuiltYieldsANullColumn(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('error');
        // Once for the preload attempt, once for the row that still asks for a value.
        $logger->expects(self::exactly(2))->method('critical');

        $result = $this->serializeWith(UnbuildablePreloadingProcessor::class, $logger);

        self::assertNull($result[0]['processed']);
    }

    private function serializeWith(string $processorClass, LoggerInterface $logger): array
    {
        $schema = $this->createMock(TcaSchema::class);
        $schema->method('hasField')->willReturn(false);

        $schemaFactory = $this->createMock(TcaSchemaFactory::class);
        $schemaFactory->method('get')->willReturn($schema);

        $serializer = new ResourceSerializer(
            $schemaFactory,
            new CacheTagCollector(),
            (new \ReflectionClass(FileFieldSerializer::class))->newInstanceWithoutConstructor(),
            (new \ReflectionClass(RelationSerializer::class))->newInstanceWithoutConstructor(),
            new ProcessorGuard($logger),
        );

        return $serializer->serializeCollection(
            [['uid' => 1]],
            ApiDefinition::fromArray([
                'general' => [
                    'table'        => 'tx_test',
                    'resourceName' => 'tests',
                    'resourceType' => 'Test',
                ],
                'columns' => [
                    'missing' => ['groups' => ['list']],
                ],
                'virtualProperties' => [
                    'processed' => [
                        'groups'    => ['list'],
                        'processor' => $processorClass,
                    ],
                ],
            ]),
            '/_api/tests',
            operation: 'list',
        );
    }
}

/** A processor whose constructor the container cannot satisfy. */
final class UnbuildablePreloadingProcessor implements ColumnProcessorInterface, PreloadingProcessorInterface
{
    public function __construct(private readonly string $required)
    {
    }

    public function prepare(array $rows, ApiDefinition $config): void
    {
    }

    public function process(mixed $value, ColumnDefinition $config, array $context): mixed
    {
        return $this->required;
    }
}

final class SucceedingPreloadingProcessor implements ColumnProcessorInterface, PreloadingProcessorInterface
{
    private bool $prepared = false;

    public function prepare(array $rows, ApiDefinition $config): void
    {
        $this->prepared = true;
    }

    public function process(mixed $value, ColumnDefinition $config, array $context): mixed
    {
        return $this->prepared ? 'prepared' : 'unprepared';
    }
}

final class FailingPreloadingProcessor implements ColumnProcessorInterface, PreloadingProcessorInterface
{
    private bool $prepared = false;

    public function prepare(array $rows, ApiDefinition $config): void
    {
        $this->prepared = true;

        throw new \RuntimeException('Preloading failed');
    }

    public function process(mixed $value, ColumnDefinition $config, array $context): mixed
    {
        return $this->prepared ? 'prepared' : 'unprepared';
    }
}
