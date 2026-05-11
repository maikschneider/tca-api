<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Filter;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;

#[AutoconfigureTag('tca_api.filter')]
interface FilterInterface
{
    public function apply(QueryBuilder $qb, FilterContext $context): void;
}
