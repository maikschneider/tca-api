<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Unit\DependencyInjection;

use MaikSchneider\TcaApi\Configuration\ApiDefinition;
use MaikSchneider\TcaApi\Configuration\ColumnDefinition;
use MaikSchneider\TcaApi\DependencyInjection\PreloadingProcessorCompilerPass;
use MaikSchneider\TcaApi\Serializer\Processing\ColumnProcessorInterface;
use MaikSchneider\TcaApi\Serializer\Processing\PreloadingProcessorInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class PreloadingProcessorCompilerPassTest extends TestCase
{
    #[Test]
    public function makesPreloadingProcessorServicesNonShared(): void
    {
        $container = new ContainerBuilder();
        $container->register(CompilerPassPreloadingProcessor::class)
            ->setPublic(true)
            ->setShared(true)
            ->setAutoconfigured(true);
        $container->register(CompilerPassRegularProcessor::class)
            ->setPublic(true)
            ->setShared(true)
            ->setAutoconfigured(true);
        $container->registerForAutoconfiguration(PreloadingProcessorInterface::class)
            ->addTag(PreloadingProcessorInterface::SERVICE_TAG);
        $container->addCompilerPass(new PreloadingProcessorCompilerPass(), PassConfig::TYPE_BEFORE_REMOVING);

        $container->compile();

        self::assertNotSame(
            $container->get(CompilerPassPreloadingProcessor::class),
            $container->get(CompilerPassPreloadingProcessor::class),
        );
        self::assertSame(
            $container->get(CompilerPassRegularProcessor::class),
            $container->get(CompilerPassRegularProcessor::class),
        );
    }

    #[Test]
    public function doesNotAutoloadUnrelatedServiceClasses(): void
    {
        $container = new ContainerBuilder();
        $container->register('unrelated', 'TYPO3\\CMS\\Install\\Report\\EnvironmentStatusReport');
        $container->register(CompilerPassPreloadingProcessor::class)
            ->addTag(PreloadingProcessorInterface::SERVICE_TAG);

        (new PreloadingProcessorCompilerPass())->process($container);

        self::assertTrue($container->getDefinition('unrelated')->isShared());
        self::assertFalse($container->getDefinition(CompilerPassPreloadingProcessor::class)->isShared());
    }
}

final class CompilerPassPreloadingProcessor implements ColumnProcessorInterface, PreloadingProcessorInterface
{
    public function prepare(array $rows, ApiDefinition $config): void
    {
    }

    public function process(mixed $value, ColumnDefinition $config, array $context): mixed
    {
        return $value;
    }
}

final class CompilerPassRegularProcessor implements ColumnProcessorInterface
{
    public function process(mixed $value, ColumnDefinition $config, array $context): mixed
    {
        return $value;
    }
}
