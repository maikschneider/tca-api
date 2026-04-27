<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Filter;

use MaikSchneider\TcaApi\Configuration\ApiDefinition;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Typed context object passed to every FilterInterface::apply() call.
 *
 * Well-known fields are named properties; filter-specific options from the
 * resource config are available via options[] and the option() helper.
 * $request and $resourceConfig are nullable — no built-in filter requires them,
 * but custom filters running in a full request context will find them set.
 */
final readonly class FilterContext
{
    /**
     * @param mixed                $value          Filter value from the request (or a private/default value).
     * @param string               $table          Resource table name.
     * @param string               $column         Column name this filter is applied to.
     * @param array<string, mixed> $options        Filter-specific options from the resource config (e.g. 'columns', 'match', 'type', 'mm_table', …).
     * @param ServerRequestInterface|null $request PSR-7 request — available in HTTP context; null in unit tests.
     * @param ApiDefinition|null   $resourceConfig Full resource config — available in HTTP context; null in unit tests.
     */
    public function __construct(
        public readonly mixed $value,
        public readonly string $table,
        public readonly string $column,
        public readonly array $options = [],
        public readonly ?ServerRequestInterface $request = null,
        public readonly ?ApiDefinition $resourceConfig = null,
    ) {
    }

    /**
     * Returns a filter-specific option, or $default when not set.
     */
    public function option(string $key, mixed $default = null): mixed
    {
        return $this->options[$key] ?? $default;
    }

    /**
     * Returns a copy of this context with additional options merged in.
     * Use in filter implementations that need to derive missing config at runtime
     * (e.g. MmFilter deriving mm_table from TCA when it is not set explicitly).
     *
     * @param array<string, mixed> $extra
     */
    public function withOptions(array $extra): self
    {
        return new self(
            value:          $this->value,
            table:          $this->table,
            column:         $this->column,
            options:        array_merge($this->options, $extra),
            request:        $this->request,
            resourceConfig: $this->resourceConfig,
        );
    }
}
