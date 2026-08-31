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

        self::assertSame('unprepared', $result[0]['processed']);
    }
}

final class FailingPreloadingProcessor implements ColumnProcessorInterface, PreloadingProcessorInterface
{
    public function prepare(array $rows, ApiDefinition $config): void
    {
        throw new \RuntimeException('Preloading failed');
    }

    public function process(mixed $value, ColumnDefinition $config, array $context): mixed
    {
        return 'unprepared';
    }
}
