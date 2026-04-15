<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Filter;

use TYPO3\CMS\Core\Database\Query\QueryBuilder;

final class SearchFilter implements FilterInterface
{
    public function getStrategy(): string
    {
        return 'search';
    }

    public function apply(QueryBuilder $qb, string $column, array $filterConfig): void
    {
        $columns = $filterConfig['columns'] ?? [];
        if ($columns === []) {
            return;
        }

        $value   = (string)$filterConfig['value'];
        $match   = $filterConfig['match'] ?? 'partial';
        $escaped = $qb->escapeLikeWildcards($value);
        $pattern = match ($match) {
            'word_start' => $escaped . '%',
            default      => '%' . $escaped . '%',
        };

        $orParts = [];
        foreach ($columns as $col) {
            $orParts[] = $qb->expr()->like($col, $qb->createNamedParameter($pattern));
        }

        $qb->andWhere($qb->expr()->or(...$orParts));
    }
}
