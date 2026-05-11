<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Filter;

/**
 * Filters that need to derive expensive configuration (e.g. TCA lookups)
 * at load time instead of per-request can implement this interface.
 *
 * preResolve() is called once per filter column during API definition build.
 * The returned FilterDefinition is cached so that subsequent boots skip the
 * expensive work entirely.
 */
interface FilterPreResolvableInterface
{
    public function preResolve(FilterDefinition $definition, string $table, string $column): FilterDefinition;
}
