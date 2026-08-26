<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\DependencyInjection;

use MaikSchneider\TcaApi\Serializer\FileProcessing\FileProcessorInterface;
use MaikSchneider\TcaApi\Serializer\Processing\ColumnProcessorInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Keeps processors reachable from the container.
 *
 * Processors are named by class-string in a resource config and instantiated
 * with GeneralUtility::makeInstance(), which only injects constructor
 * dependencies for services the container exposes. Extensions default to
 * `public: false`, so a private processor definition is dropped during
 * compilation and makeInstance() falls back to `new` — a processor with a
 * constructor then dies with an ArgumentCountError that points nowhere near
 * the cause.
 *
 * Marking every processor public makes normal constructor injection work
 * without each extension having to know about this.
 */
final class PublicProcessorPass implements CompilerPassInterface
{
    private const PROCESSOR_INTERFACES = [
        ColumnProcessorInterface::class,
        FileProcessorInterface::class,
    ];

    public function process(ContainerBuilder $container): void
    {
        foreach ($container->getDefinitions() as $definition) {
            $class = $definition->getClass();

            if ($class === null || !class_exists($class)) {
                continue;
            }

            foreach (self::PROCESSOR_INTERFACES as $interface) {
                if (is_a($class, $interface, true)) {
                    $definition->setPublic(true);
                    continue 2;
                }
            }
        }
    }
}
