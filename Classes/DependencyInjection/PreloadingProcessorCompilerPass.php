<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\DependencyInjection;

use MaikSchneider\TcaApi\Serializer\Processing\ColumnProcessorInterface;
use MaikSchneider\TcaApi\Serializer\Processing\PreloadingProcessorInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Prevents collection batch state from leaking through shared processor services.
 */
final class PreloadingProcessorCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        foreach ($container->getDefinitions() as $serviceId => $definition) {
            $class = $definition->getClass() ?? $serviceId;
            if (!is_a($class, ColumnProcessorInterface::class, true)
                || !is_a($class, PreloadingProcessorInterface::class, true)
            ) {
                continue;
            }

            $definition->setShared(false);
        }
    }
}
