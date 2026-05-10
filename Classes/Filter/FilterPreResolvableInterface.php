<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Filter;

/**
 * Optional interface for filters that need to derive expensive configuration —
 * such as TCA schema lookups — before the first request is processed.
 *
 * ApiDefinitionLoader calls preResolve() once per filter column during the
 * initial definition build (cache miss). The returned FilterDefinition, with
 * derived options already merged in, is stored in the ApiDefinition cache entry.
 * Subsequent boots load the pre-resolved definition directly without repeating
 * the expensive work.
 *
 * Implementing this interface is optional. Filters that do not implement it
 * continue to derive configuration inside apply() on every request (existing
 * behaviour). apply() must remain safe when preResolve() was never called —
 * this is required for unit-test contexts where no loader is involved.
 */
interface FilterPreResolvableInterface
{
    /**
     * Pre-resolve expensive filter configuration at load time.
     *
     * Inspect $definition->table / $definition->column and any existing
     * $definition->options. Return a new FilterDefinition (via withOptions())
     * with derived values merged in, or return $definition unchanged if
     * pre-resolution is not possible (e.g. empty table in unit-test context).
     */
    public function preResolve(FilterDefinition $definition): FilterDefinition;
}
