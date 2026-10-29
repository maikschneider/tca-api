<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Contract;

use PHPUnit\Event\Facade as EventFacade;
use PHPUnit\Event\TestRunner\ExecutionFinished;
use PHPUnit\Event\TestRunner\ExecutionFinishedSubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

/**
 * Fails a complete functional run in CI when the contract baseline lists a mismatch
 * that no response produced, so a fixed mismatch can't come back unnoticed.
 *
 * Only a run of the whole Functional suite can tell, so filtered and local runs are skipped.
 */
final class UnmatchedBaselineExtension implements Extension
{
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        if (!self::isCompleteFunctionalRunInCi($configuration)) {
            return;
        }

        $facade->registerSubscriber(new class() implements ExecutionFinishedSubscriber {
            public function notify(ExecutionFinished $event): void
            {
                $unmatched = ContractBaseline::unmatched();
                if ($unmatched === []) {
                    return;
                }

                EventFacade::emitter()->testRunnerTriggeredPhpunitWarning(sprintf(
                    "%s lists mismatches that no response produced. Remove them, see Tests/Functional/Contract/README.md:\n%s",
                    basename(ContractBaseline::file()),
                    implode("\n", $unmatched),
                ));
            }
        });
    }

    private static function isCompleteFunctionalRunInCi(Configuration $configuration): bool
    {
        $ci      = getenv('CI');
        $collect = getenv(ContractBaseline::COLLECT_ENV);
        if (!\is_string($ci) || $ci === '' || (\is_string($collect) && $collect !== '')) {
            return false;
        }

        // PHPUnit 13 replaced includeTestSuite()/excludeTestSuite() with list variants.
        $included = method_exists($configuration, 'includeTestSuites') // @phpstan-ignore function.impossibleType
            ? $configuration->includeTestSuites()
            : array_filter([$configuration->includeTestSuite()]);
        $excluded = method_exists($configuration, 'excludeTestSuites') // @phpstan-ignore function.impossibleType
            ? $configuration->excludeTestSuites()
            : array_filter([$configuration->excludeTestSuite()]);
        if ($included === [] && $configuration->hasDefaultTestSuite()) {
            $included = [$configuration->defaultTestSuite()];
        }

        return $included === ['Functional']
            && $excluded === []
            && !$configuration->hasCliArguments()
            && !$configuration->hasFilter()
            && !$configuration->hasExcludeFilter()
            && !$configuration->hasGroups()
            && !$configuration->hasExcludeGroups()
            && !$configuration->hasTestsCovering()
            && !$configuration->hasTestsUsing();
    }
}
