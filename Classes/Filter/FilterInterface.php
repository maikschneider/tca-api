<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Filter;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;

#[AutoconfigureTag('tca_api.filter')]
interface FilterInterface
{
    /**
     * Apply this filter's WHERE constraint to the QueryBuilder.
     *
     * $filterConfig always contains:
     *   - 'value'           : filter value from the request
     *   - '_table'          : resource table name
     *   - '_column'         : column name (same as $column param)
     *   - '_request'        : ServerRequestInterface — access query params, auth context, etc.
     *   - '_resourceConfig' : full resource config (general, columns, filters, order, …)
     *   Plus any custom keys declared in the resource's filter config.
     */
    public function apply(QueryBuilder $qb, string $column, array $filterConfig): void;
}
