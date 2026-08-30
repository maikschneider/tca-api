<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Unit\DependencyInjection;

use MaikSchneider\TcaApi\Configuration\ApiDefinition;
use MaikSchneider\TcaApi\Configuration\ColumnDefinition;
use MaikSchneider\TcaApi\Serializer\Processing\ColumnProcessorInterface;
use MaikSchneider\TcaApi\Serializer\Processing\PreloadingProcessorInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class PreloadingProcessorAutoconfigurationTest extends TestCase
{
    #[Test]
    public function makesPreloadingProcessorServicesNonShared(): void
    {
        $container = new ContainerBuilder();
        $container->register(AutoconfiguredPreloadingProcessor::class)
            ->setPublic(true)
            ->setAutoconfigured(true);
        $container->register(AutoconfiguredRegularProcessor::class)
            ->setPublic(true)
            ->setAutoconfigured(true);
        $container->registerForAutoconfiguration(PreloadingProcessorInterface::class)
            ->setShared(false);

        $container->compile();

        self::assertNotSame(
            $container->get(AutoconfiguredPreloadingProcessor::class),
            $container->get(AutoconfiguredPreloadingProcessor::class),
        );
        self::assertSame(
            $container->get(AutoconfiguredRegularProcessor::class),
            $container->get(AutoconfiguredRegularProcessor::class),
        );
    }
}

final class AutoconfiguredPreloadingProcessor implements ColumnProcessorInterface, PreloadingProcessorInterface
{
    public function prepare(array $rows, ApiDefinition $config): void
    {
    }

    public function process(mixed $value, ColumnDefinition $config, array $context): mixed
    {
        return $value;
    }
}

final class AutoconfiguredRegularProcessor implements ColumnProcessorInterface
{
    public function process(mixed $value, ColumnDefinition $config, array $context): mixed
    {
        return $value;
    }
}
