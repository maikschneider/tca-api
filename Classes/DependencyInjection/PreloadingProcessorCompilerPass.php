<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\DependencyInjection;

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
        foreach ($container->findTaggedServiceIds(PreloadingProcessorInterface::SERVICE_TAG) as $serviceId => $_tags) {
            $container->findDefinition($serviceId)->setShared(false);
        }
    }
}
