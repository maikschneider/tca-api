<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\DependencyInjection;

use MaikSchneider\TcaApi\Serializer\Processing\RouteEnhancerProcessor;
use MaikSchneider\TcaApi\Serializer\Processing\TypoLinkProcessor;
use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Processors are named by class-string in a resource config and built with
 * makeInstance(), which only injects constructor dependencies for services the
 * container exposes. Autoconfiguration marks them public so an extension does
 * not have to know that.
 *
 * Both processors below have constructor dependencies and no `public: true` of
 * their own — building them proves autoconfiguration applied.
 */
final class ProcessorAutoconfigurationTest extends ApiFunctionalTestCase
{
    public function testProcessorWithConstructorDependenciesIsResolvedFromTheContainer(): void
    {
        $processor = GeneralUtility::makeInstance(TypoLinkProcessor::class);

        self::assertInstanceOf(TypoLinkProcessor::class, $processor);
        self::assertSame($processor, $this->get(TypoLinkProcessor::class));
    }

    public function testEveryBuiltInColumnProcessorIsPublic(): void
    {
        foreach ([TypoLinkProcessor::class, RouteEnhancerProcessor::class] as $processorClass) {
            self::assertTrue(
                $this->getContainer()->has($processorClass),
                $processorClass . ' is not reachable from the container',
            );
        }
    }
}
