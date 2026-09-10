<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Filter;

/**
 * Marks a filter that accepts a list of values as well as a single one
 * (`?filters[column][]=1&filters[column][]=2`) — see {@see ValueSet}.
 *
 * Implement it alongside FilterInterface in custom filters that normalise their
 * value through ValueSet, so the OpenAPI spec advertises the repeatable form.
 */
interface MultiValueFilterInterface
{
}
