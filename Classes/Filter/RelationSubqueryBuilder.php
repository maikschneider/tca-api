<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Filter;

use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;

/**
 * Resolves a dotted relation path and builds the `{uid} IN (subquery)` set that maps a
 * related record back to the holder record. Shared by {@see RelationPathFilter} (whole
 * filter) and {@see SearchFilter} (one OR-part per related column).
 *
 * The subquery is built inside-out and de-duplicating, so pagination/counts stay correct,
 * and every hop is built through a table-bound QueryBuilder so enable-field restrictions
 * (deleted, hidden, start/end time, fe_group) apply at each level. All leaf parameters are
 * namespaced onto the outer query builder to avoid `:dcValue*` collisions.
 */
final class RelationSubqueryBuilder
{
    /**
     * Hard cap on the number of relation hops in a single path (the leaf column is not a
     * hop). Bounds the depth of nested subqueries so a misconfigured path cannot generate
     * an unbounded query.
     */
    public const MAX_RELATION_HOPS = 3;

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly RelationResolver $resolver,
    ) {
    }

    /**
     * Resolves and validates a dotted path into its hops, leaf table and leaf column.
     * Throws on an unknown relation segment, unknown leaf column, or too many hops — used
     * for boot-time validation via preResolve().
     *
     * @return array{0: list<RelationHop>, 1: string, 2: string}
     *
     * @throws \InvalidArgumentException
     */
    public function resolvePath(string $table, string $path): array
    {
        $segments   = explode('.', $path);
        $leafColumn = (string)array_pop($segments);
        if ($segments === [] || $leafColumn === '') {
            throw new \InvalidArgumentException(
                sprintf('Relation path "%s" must be of the form "relation.….column".', $path),
            );
        }

        if (\count($segments) > self::MAX_RELATION_HOPS) {
            throw new \InvalidArgumentException(sprintf(
                'Relation path "%s" exceeds the maximum of %d relation hops.',
                $path,
                self::MAX_RELATION_HOPS,
            ));
        }

        $hops    = [];
        $current = $table;
        foreach ($segments as $segment) {
            $hop     = $this->resolver->resolve($current, $segment);
            $hops[]  = $hop;
            $current = $hop->targetTable;
        }

        // The leaf column is compared in SQL against the resolved leaf table, so a typo
        // there would otherwise only fail at runtime. Reject it here (i.e. at boot) —
        // guarded so a leaf table without TCA columns is not falsely rejected, and the
        // universal system columns uid/pid (absent from TCA `columns`) are allowed.
        $leafColumns = $GLOBALS['TCA'][$current]['columns'] ?? null;
        if (\is_array($leafColumns)
            && $leafColumn !== 'uid'
            && $leafColumn !== 'pid'
            && !isset($leafColumns[$leafColumn])
        ) {
            throw new \InvalidArgumentException(sprintf(
                'Relation path: leaf column "%s.%s" is not a known TCA column.',
                $current,
                $leafColumn,
            ));
        }

        return [$hops, $current, $leafColumn];
    }

    /**
     * Builds the SQL selecting the holder-table UIDs for a resolved path. The leaf WHERE is
     * populated by $applyLeaf against a query builder scoped to the leaf table (alias `leaf`).
     * The returned SQL is meant to be wrapped by the caller as `{alias}.uid IN (<sql>)`.
     *
     * @param list<RelationHop>                     $hops
     * @param \Closure(QueryBuilder, string): void  $applyLeaf
     */
    public function buildUidSubquery(
        QueryBuilder $outerQb,
        array $hops,
        string $leafTable,
        string $paramPrefix,
        \Closure $applyLeaf,
    ): string {
        $leafQb = $this->connectionPool->getQueryBuilderForTable($leafTable);
        $leafQb->select('leaf.uid')->from($leafTable, 'leaf');
        $applyLeaf($leafQb, 'leaf');

        $currentSet = $this->rebindParameters($leafQb, $outerQb, $paramPrefix);

        for ($i = \count($hops) - 1; $i >= 0; $i--) {
            $currentSet = $this->wrapHop($hops[$i], $currentSet, $i);
        }

        return $currentSet;
    }

    private function wrapHop(RelationHop $hop, string $innerSet, int $level): string
    {
        $srcAlias = 'src' . $level;
        $sub      = $this->connectionPool->getQueryBuilderForTable($hop->sourceTable);
        $sub->select($srcAlias . '.uid')->from($hop->sourceTable, $srcAlias);

        if ($hop->kind === RelationHop::KIND_MM) {
            // Source rows linked through the MM table to a UID in the inner set.
            $mmAlias = 'mm' . $level;
            $sub->join(
                $srcAlias,
                $hop->mmTable,
                $mmAlias,
                $sub->expr()->eq($mmAlias . '.' . $hop->mmSourceKey, $sub->quoteIdentifier($srcAlias . '.uid')),
            )->where($sub->expr()->in($mmAlias . '.' . $hop->mmTargetKey, '(' . $innerSet . ')'));

            foreach ($hop->mmMatch as $col => $val) {
                $sub->andWhere($sub->expr()->eq($mmAlias . '.' . $col, $sub->quote((string)$val)));
            }

            return $sub->getSQL();
        }

        if ($hop->kind === RelationHop::KIND_INLINE) {
            // Source rows whose inline child (back-pointing via foreignField) is in the
            // inner set. Joining the child table restricts it too; the parent stays
            // restricted through the source table this query is built on.
            $childAlias = 'inl' . $level;
            $sub->join(
                $srcAlias,
                $hop->targetTable,
                $childAlias,
                $sub->expr()->eq($childAlias . '.' . $hop->foreignField, $sub->quoteIdentifier($srcAlias . '.uid')),
            )->where($sub->expr()->in($childAlias . '.uid', '(' . $innerSet . ')'));

            if ($hop->foreignTableField !== null) {
                $sub->andWhere($sub->expr()->eq(
                    $childAlias . '.' . $hop->foreignTableField,
                    $sub->quote($hop->sourceTable),
                ));
            }
            foreach ($hop->foreignMatchFields as $col => $val) {
                $sub->andWhere($sub->expr()->eq($childAlias . '.' . $col, $sub->quote((string)$val)));
            }

            return $sub->getSQL();
        }

        // FK hop: source rows whose fkColumn points into the inner set.
        $sub->where($sub->expr()->in($srcAlias . '.' . $hop->fkColumn, '(' . $innerSet . ')'));

        return $sub->getSQL();
    }

    /**
     * Lifts every parameter bound on $from onto $to under a unique prefix, and rewrites
     * the corresponding placeholders in $from's SQL. Returns the rewritten SQL.
     */
    private function rebindParameters(QueryBuilder $from, QueryBuilder $to, string $prefix): string
    {
        $sql    = $from->getSQL();
        $params = $from->getParameters();
        if ($params === []) {
            return $sql;
        }

        $types = $from->getParameterTypes();

        $sql = preg_replace_callback(
            '/:(\w+)/',
            static fn (array $m): string => \array_key_exists($m[1], $params) ? ':' . $prefix . $m[1] : $m[0],
            $sql,
        ) ?? $sql;

        foreach ($params as $name => $value) {
            $to->setParameter($prefix . $name, $value, $types[$name] ?? ParameterType::STRING);
        }

        return $sql;
    }
}
