<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Serializer\Processing;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * Error-containment boundary around column and file processors.
 *
 * A processor operates on one cell of one record. Without a boundary, a single
 * corrupt value — a malformed crop JSON, an unresolvable link, a broken FlexForm —
 * propagates out of the serializer and fails the entire API response, so one bad
 * row takes down a whole collection endpoint.
 *
 * This guard scopes that blast radius to the field: a throwing processor yields
 * null for that column, and the failure is logged with table, uid, column and
 * processor class so the offending record stays findable.
 *
 * Catching \Throwable is deliberate here and acceptable *only* because the catch
 * is both scoped to a single field and logged. Processors themselves must not
 * blanket-catch — an unlogged swallow inside a processor hides genuine bugs
 * (a typo'd method call silently becomes "this column has no value").
 *
 * When the site setting tca_api.debugMode is enabled the original throwable is
 * re-thrown instead, so development and CI fail loudly while production degrades.
 */
final class ProcessorGuard
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Run a processor callable, degrading to null if it throws.
     *
     * @param callable(): mixed $process       The processor invocation to guard
     * @param class-string      $processorClass Processor being invoked, for the log context
     * @param string            $table          Table the record belongs to
     * @param string            $column         Column being processed
     * @param int|string        $uid            Record uid
     *
     * @throws \Throwable when tca_api.debugMode is enabled for the current site
     */
    public function run(
        callable $process,
        string $processorClass,
        string $table,
        string $column,
        int|string $uid,
    ): mixed {
        try {
            return $process();
        } catch (\Throwable $throwable) {
            if ($this->isDebugMode()) {
                throw $throwable;
            }

            $this->logger->error('TCA API column processing failed', [
                'table'     => $table,
                'uid'       => $uid,
                'column'    => $column,
                'processor' => $processorClass,
                'exception' => $throwable::class,
                'message'   => $throwable->getMessage(),
                'file'      => $throwable->getFile(),
                'line'      => $throwable->getLine(),
            ]);

            return null;
        }
    }

    /**
     * Reads tca_api.debugMode from the current site. Defaults to false whenever the
     * setting cannot be resolved (no request, no site) so the guard degrades rather
     * than throwing in contexts that have no site context at all.
     */
    private function isDebugMode(): bool
    {
        $request = $GLOBALS['TYPO3_REQUEST'] ?? null;
        if (!$request instanceof ServerRequestInterface) {
            return false;
        }

        $site = $request->getAttribute('site');
        if (!$site instanceof Site) {
            return false;
        }

        return (bool)$site->getSettings()->get('tca_api.debugMode', false);
    }
}
