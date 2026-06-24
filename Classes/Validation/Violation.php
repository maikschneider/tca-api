<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Validation;

/**
 * A single validation failure returned by a ValidatorInterface implementation.
 *
 * $propertyPath defaults to the validated column when null; set it explicitly
 * to point a violation at a different field (e.g. for cross-field rules).
 */
final readonly class Violation
{
    public function __construct(
        public string $message,
        public string $code,
        public ?string $propertyPath = null,
    ) {
    }
}
