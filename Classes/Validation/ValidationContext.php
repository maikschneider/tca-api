<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Validation;

use MaikSchneider\TcaApi\Configuration\ApiDefinition;

/**
 * Typed context object passed to every ValidatorInterface::validate() call.
 *
 * Mirrors FilterContext: well-known fields are named properties and
 * validator-specific options from the resource config are available via
 * options[] and the option() helper. $body carries the full request payload so
 * a validator can implement cross-field rules; $partial is true for PATCH.
 */
final readonly class ValidationContext
{
    /**
     * @param mixed                $value          Value of the column being validated.
     * @param string               $table          Resource table name.
     * @param string               $column         Column name this validator is applied to.
     * @param array<string, mixed> $options        Validator-specific options from the resource config.
     * @param array<string, mixed> $body           Full request body (enables cross-field validation).
     * @param bool                 $partial        True on PATCH (partial update) requests.
     * @param ApiDefinition|null   $resourceConfig Full resource config; null in unit tests.
     */
    public function __construct(
        public readonly mixed $value,
        public readonly string $table,
        public readonly string $column,
        public readonly array $options = [],
        public readonly array $body = [],
        public readonly bool $partial = false,
        public readonly ?ApiDefinition $resourceConfig = null,
    ) {
    }

    /**
     * Returns a validator-specific option, or $default when not set.
     */
    public function option(string $key, mixed $default = null): mixed
    {
        return $this->options[$key] ?? $default;
    }
}
