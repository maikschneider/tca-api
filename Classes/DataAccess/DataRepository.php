<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\DataAccess;

use MaikSchneider\TcaApi\Configuration\ApiDefinition;
use MaikSchneider\TcaApi\Filter\FilterInterface;
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;

final class DataRepository
{
    /** @var array<string, FilterInterface>|null */
    private ?array $filterMap = null;

    /**
     * @param iterable<FilterInterface> $filterHandlers
     */
    public function __construct(
        private readonly ConnectionPool $connectionPool,
        #[TaggedIterator('tca_api.filter')]
        private readonly iterable $filterHandlers,
    ) {
    }

    public function findByIds(string $table, array $uids): array
    {
        if ($uids === []) {
            return [];
        }

        $qb = $this->connectionPool->getQueryBuilderForTable($table);
        $rows = $qb->select('*')
            ->from($table)
            ->where(
                $qb->expr()->in('uid', array_map(
                    fn (int $uid) => $qb->createNamedParameter($uid),
                    $uids,
                ))
            )
            ->executeQuery()
            ->fetchAllAssociative();

        $indexed = [];
        foreach ($rows as $row) {
            $indexed[(int)$row['uid']] = $row;
        }

        return $indexed;
    }

    public function findById(string $table, int $uid, ApiDefinition $config, ?SiteLanguage $language = null): ?array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable($table);
        $qb->select('*')
            ->from($table)
            ->where(
                $qb->expr()->eq('uid', $qb->createNamedParameter($uid))
            );

        $this->applyPidConstraint($qb, $config);
        $this->applyLanguageConstraint($qb, $config, $language);

        $row = $qb->executeQuery()->fetchAssociative();
        if ($row === false) {
            return null;
        }

        return $this->applyLanguageOverlay([$row], $config, $language)[0] ?? null;
    }

    public function findCollection(string $table, array $constraints, int $limit, int $offset, array $order, ApiDefinition $config, ?SiteLanguage $language = null): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable($table);
        $qb->select('*')
            ->from($table);

        $this->applyPidConstraint($qb, $config);
        $this->applyLanguageConstraint($qb, $config, $language);

        foreach ($constraints as [$filterClass, $context]) {
            $this->resolveFilter($filterClass)->apply($qb, $context);
        }

        foreach ($order as $column => $direction) {
            $qb->addOrderBy($column, $direction);
        }

        if ($limit > 0) {
            $qb->setMaxResults($limit);
        }
        if ($offset > 0) {
            $qb->setFirstResult($offset);
        }

        return $this->applyLanguageOverlay($qb->executeQuery()->fetchAllAssociative(), $config, $language);
    }

    public function count(string $table, array $constraints, ApiDefinition $config, ?SiteLanguage $language = null): int
    {
        $qb = $this->connectionPool->getQueryBuilderForTable($table);
        if ($this->needsStrictLanguageCount($config, $language)) {
            // Strict mode counts via applyLanguageOverlay, which needs the languageField
            // column to honour the sys_language_uid = -1 ("all languages") exemption.
            $languageField = $this->languageField($table);
            $qb->select('uid', $languageField !== null ? $languageField : 'uid');
        } else {
            $qb->count('uid');
        }
        $qb->from($table);

        $this->applyPidConstraint($qb, $config);
        $this->applyLanguageConstraint($qb, $config, $language);

        foreach ($constraints as [$filterClass, $context]) {
            $this->resolveFilter($filterClass)->apply($qb, $context);
        }

        if ($this->needsStrictLanguageCount($config, $language)) {
            return \count($this->applyLanguageOverlay($qb->executeQuery()->fetchAllAssociative(), $config, $language));
        }

        return (int)$qb->executeQuery()->fetchOne();
    }

    /**
     * Bulk-fetch hasMany related records via an MM intermediate table.
     * Returns [parentUid => [rows]] preserving MM sorting order.
     *
     * When $language is provided, only default-language (sys_language_uid IN (0,-1)) rows
     * are fetched and language overlay is applied to the result set.
     */
    public function findHasManyByMM(
        string $foreignTable,
        array $parentUids,
        string $mmTable,
        string $mmParentKey,
        string $mmForeignKey,
        array $mmConstraints = [],
        ?SiteLanguage $language = null,
    ): array {
        if ($parentUids === []) {
            return [];
        }

        $qb = $this->connectionPool->getQueryBuilderForTable($foreignTable);
        $qb->select('f.*', 'mm.' . $mmParentKey . ' AS __parent_uid')
            ->from($foreignTable, 'f')
            ->join(
                'f',
                $mmTable,
                'mm',
                $qb->expr()->eq('f.uid', $qb->quoteIdentifier('mm.' . $mmForeignKey)),
            )
            ->where($qb->expr()->in(
                'mm.' . $mmParentKey,
                array_map(fn (int $uid) => $qb->createNamedParameter($uid), $parentUids),
            ))
            ->addOrderBy('mm.sorting');

        foreach ($mmConstraints as $col => $val) {
            $qb->andWhere($qb->expr()->eq('mm.' . $col, $qb->createNamedParameter($val)));
        }

        $this->applyLanguageConstraintForTable($qb, $foreignTable, $language, 'f');

        $rows = $qb->executeQuery()->fetchAllAssociative();

        $grouped = [];
        foreach ($rows as $row) {
            $parentUid = (int)$row['__parent_uid'];
            unset($row['__parent_uid']);
            $grouped[$parentUid][] = $row;
        }

        // Apply language overlay per parent group to preserve grouping.
        if ($language !== null && $language->getLanguageId() !== 0) {
            foreach ($grouped as $parentUid => $parentRows) {
                $grouped[$parentUid] = $this->applyLanguageOverlayForTable($parentRows, $foreignTable, $language);
            }
        }

        return $grouped;
    }

    /**
     * Bulk-fetch reverse-side MM relations for a polymorphic `MM_oppositeUsage` column.
     *
     * Selects from the MM table directly (no JOIN) so that the heterogeneous set of
     * forward-side tables can be enumerated in a single round-trip. The caller is
     * responsible for bulk-fetching each `{table, uid}` pair via `findByIds`.
     *
     * Query shape (logically):
     *   SELECT uid_local, uid_foreign, tablenames, fieldname, sorting_foreign
     *   FROM   {mmTable}
     *   WHERE  uid_local IN (:parentUids)
     *     AND  (
     *            (tablenames = :t1 AND fieldname IN (:f1a, :f1b))
     *         OR (tablenames = :t2 AND fieldname IN (:f2a))
     *          )
     *     AND  {extraConstraints}    -- each entry rendered as `mm.col = val`
     *   ORDER BY uid_local, sorting_foreign
     *
     * Result preserves `sorting_foreign` order per parent. Empty `$parentUids` or
     * empty `$oppositeUsage` short-circuit to `[]`. The caller (typically the
     * preloader) must ensure `$oppositeUsage` is non-empty for a wildcard column;
     * the boot-time validator in `ApiDefinitionLoader` rejects the discovery path.
     *
     * @param list<int>                   $parentUids
     * @param array<string, list<string>> $oppositeUsage     tablename => [fieldnames]
     * @param array<string, scalar>       $extraConstraints  applied as `mm.col = val`
     * @return array<int, list<array{table: string, uid: int}>>
     */
    public function findReverseMmRelations(
        array $parentUids,
        string $mmTable,
        array $oppositeUsage,
        array $extraConstraints = [],
    ): array {
        if ($parentUids === [] || $oppositeUsage === []) {
            return [];
        }

        $qb = $this->connectionPool->getQueryBuilderForTable($mmTable);
        $qb->select('uid_local', 'uid_foreign', 'tablenames', 'fieldname', 'sorting_foreign')
            ->from($mmTable)
            ->where($qb->expr()->in(
                'uid_local',
                array_map(fn (int $uid) => $qb->createNamedParameter($uid, Connection::PARAM_INT), $parentUids),
            ));

        $disjuncts = [];
        foreach ($oppositeUsage as $table => $fields) {
            if ($fields === []) {
                continue;
            }
            $disjuncts[] = $qb->expr()->and(
                $qb->expr()->eq('tablenames', $qb->createNamedParameter($table)),
                $qb->expr()->in(
                    'fieldname',
                    array_map(fn (string $field) => $qb->createNamedParameter($field), $fields),
                ),
            );
        }

        if ($disjuncts === []) {
            return [];
        }

        $qb->andWhere($qb->expr()->or(...$disjuncts));

        foreach ($extraConstraints as $col => $val) {
            $qb->andWhere($qb->expr()->eq($col, $qb->createNamedParameter($val)));
        }

        $qb->addOrderBy('uid_local')
            ->addOrderBy('sorting_foreign');

        $rows = $qb->executeQuery()->fetchAllAssociative();

        $grouped = [];
        foreach ($parentUids as $parentUid) {
            $grouped[$parentUid] = [];
        }
        foreach ($rows as $row) {
            $parentUid = (int)$row['uid_local'];
            $grouped[$parentUid][] = [
                'table' => (string)$row['tablenames'],
                'uid'   => (int)$row['uid_foreign'],
            ];
        }

        return $grouped;
    }

    /**
     * Bulk-fetch hasMany related records via a back-pointer (foreign_field) on the child table.
     * Applies foreign_match_fields as fixed child-table constraints when provided.
     * Returns [parentUid => [rows]] ordered by the child table's default sorting.
     *
     * When $language is provided, only default-language (sys_language_uid IN (0,-1)) rows
     * are fetched and language overlay is applied to the result set.
     */
    public function findHasManyByForeignField(
        string $foreignTable,
        string $foreignField,
        array $parentUids,
        ?string $foreignTableField = null,
        ?string $parentTable = null,
        array $foreignMatchFields = [],
        ?SiteLanguage $language = null,
    ): array {
        if ($parentUids === []) {
            return [];
        }

        $qb = $this->connectionPool->getQueryBuilderForTable($foreignTable);
        $qb->select('*')
            ->from($foreignTable)
            ->where($qb->expr()->in(
                $foreignField,
                array_map(fn (int $uid) => $qb->createNamedParameter($uid), $parentUids),
            ));

        if ($foreignTableField !== null && $parentTable !== null) {
            $qb->andWhere($qb->expr()->eq(
                $foreignTableField,
                $qb->createNamedParameter($parentTable),
            ));
        }

        foreach ($foreignMatchFields as $matchField => $matchValue) {
            $qb->andWhere($qb->expr()->eq(
                $matchField,
                $qb->createNamedParameter($matchValue),
            ));
        }

        $this->applyLanguageConstraintForTable($qb, $foreignTable, $language);

        $rows = $qb->executeQuery()->fetchAllAssociative();

        $grouped = [];
        foreach ($rows as $row) {
            $parentUid = (int)$row[$foreignField];
            $grouped[$parentUid][] = $row;
        }

        // Apply language overlay per parent group to preserve grouping.
        if ($language !== null && $language->getLanguageId() !== 0) {
            foreach ($grouped as $parentUid => $parentRows) {
                $grouped[$parentUid] = $this->applyLanguageOverlayForTable($parentRows, $foreignTable, $language);
            }
        }

        return $grouped;
    }

    private function applyPidConstraint(QueryBuilder $qb, ApiDefinition $config): void
    {
        if ($config->storagePid !== null) {
            $qb->andWhere($qb->expr()->eq('pid', $qb->createNamedParameter($config->storagePid, Connection::PARAM_INT)));
        }
    }

    private function applyLanguageConstraint(QueryBuilder $qb, ApiDefinition $config, ?SiteLanguage $language): void
    {
        if ($config->languageMode === 'ignore' || $language === null) {
            return;
        }

        $languageField = $this->languageField($config->table);
        if ($languageField === null) {
            return;
        }

        $qb->andWhere($qb->expr()->in(
            $languageField,
            $qb->createNamedParameter([0, -1], Connection::PARAM_INT_ARRAY),
        ));
    }

    /**
     * Apply language constraint for a foreign table (used by MM/foreignField queries).
     * Restricts to default-language rows (sys_language_uid IN (0, -1)) when a non-default
     * language is active and the table supports translations.
     */
    private function applyLanguageConstraintForTable(QueryBuilder $qb, string $table, ?SiteLanguage $language, string $alias = ''): void
    {
        if ($language === null || $language->getLanguageId() === 0) {
            return;
        }

        $languageField = $this->languageField($table);
        if ($languageField === null) {
            return;
        }

        $col = $alias !== '' ? $alias . '.' . $languageField : $languageField;
        $qb->andWhere($qb->expr()->in(
            $col,
            $qb->createNamedParameter([0, -1], Connection::PARAM_INT_ARRAY),
        ));
    }

    /**
     * Apply language overlay for a foreign table (without requiring an ApiDefinition).
     *
     * Used by findHasManyByMM / findHasManyByForeignField to overlay child rows
     * in the same manner as the main findById / findCollection paths.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function applyLanguageOverlayForTable(array $rows, string $table, SiteLanguage $language): array
    {
        if ($rows === []) {
            return $rows;
        }

        $languageField = $this->languageField($table);
        $parentField = $this->translationParentField($table);
        if ($languageField === null || $parentField === null) {
            return $rows;
        }

        $parentUids = array_values(array_unique(array_map(static fn (array $row): int => (int)$row['uid'], $rows)));
        $translations = $this->fetchTranslations($table, $languageField, $parentField, $language->getLanguageId(), $parentUids);
        $fallbackType = $language->getFallbackType();

        $overlaid = [];
        foreach ($rows as $row) {
            if (isset($row[$languageField]) && (int)$row[$languageField] === -1) {
                $overlaid[] = $row;
                continue;
            }

            $parentUid = (int)$row['uid'];
            if (isset($translations[$parentUid])) {
                $row = array_merge($row, $translations[$parentUid]);
                $row['uid'] = $parentUid;
                $overlaid[] = $row;
                continue;
            }

            if ($fallbackType === 'strict') {
                continue;
            }

            $overlaid[] = $row;
        }

        return $overlaid;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function applyLanguageOverlay(array $rows, ApiDefinition $config, ?SiteLanguage $language): array
    {
        if ($config->languageMode === 'ignore' || $language === null || $language->getLanguageId() === 0 || $rows === []) {
            return $rows;
        }

        $languageField = $this->languageField($config->table);
        $parentField = $this->translationParentField($config->table);
        if ($languageField === null || $parentField === null) {
            return $rows;
        }

        $parentUids = array_values(array_unique(array_map(static fn (array $row): int => (int)$row['uid'], $rows)));
        $translations = $this->fetchTranslations($config->table, $languageField, $parentField, $language->getLanguageId(), $parentUids);
        $fallbackType = $language->getFallbackType();

        $overlaid = [];
        foreach ($rows as $row) {
            // Records flagged "all languages" (sys_language_uid = -1) are visible in every
            // language and are never translated — exempt from strict drop and overlay.
            if (isset($row[$languageField]) && (int)$row[$languageField] === -1) {
                $overlaid[] = $row;
                continue;
            }

            $parentUid = (int)$row['uid'];
            if (isset($translations[$parentUid])) {
                $row = array_merge($row, $translations[$parentUid]);
                $row['uid'] = $parentUid;
                $overlaid[] = $row;
                continue;
            }

            if ($fallbackType === 'strict') {
                continue;
            }

            $overlaid[] = $row;
        }

        return $overlaid;
    }

    /**
     * @param int[] $parentUids
     * @return array<int, array<string, mixed>>
     */
    private function fetchTranslations(string $table, string $languageField, string $parentField, int $languageId, array $parentUids): array
    {
        if ($parentUids === []) {
            return [];
        }

        $qb = $this->connectionPool->getQueryBuilderForTable($table);
        $rows = $qb->select('*')
            ->from($table)
            ->where(
                $qb->expr()->eq($languageField, $qb->createNamedParameter($languageId, Connection::PARAM_INT)),
                $qb->expr()->in($parentField, $qb->createNamedParameter($parentUids, Connection::PARAM_INT_ARRAY)),
            )
            ->executeQuery()
            ->fetchAllAssociative();

        $translations = [];
        foreach ($rows as $row) {
            $translations[(int)$row[$parentField]] = $row;
        }

        return $translations;
    }

    private function needsStrictLanguageCount(ApiDefinition $config, ?SiteLanguage $language): bool
    {
        return $config->languageMode !== 'ignore'
            && $language !== null
            && $language->getLanguageId() !== 0
            && $language->getFallbackType() === 'strict'
            && $this->languageField($config->table) !== null
            && $this->translationParentField($config->table) !== null;
    }

    private function languageField(string $table): ?string
    {
        return $this->tcaCtrlField($table, 'languageField');
    }

    private function translationParentField(string $table): ?string
    {
        return $this->tcaCtrlField($table, 'transOrigPointerField');
    }

    private function tcaCtrlField(string $table, string $field): ?string
    {
        $ctrl = $GLOBALS['TCA'][$table]['ctrl'] ?? null;
        if (!\is_array($ctrl)) {
            return null;
        }

        $fieldName = $ctrl[$field] ?? null;
        if ($fieldName === null || $fieldName === '') {
            return null;
        }

        if (!\is_string($fieldName)) {
            throw new \RuntimeException(sprintf('Invalid TCA ctrl "%s" for table "%s": expected string.', $field, $table));
        }

        if (!isset($GLOBALS['TCA'][$table]['columns'][$fieldName]) || !\is_array($GLOBALS['TCA'][$table]['columns'][$fieldName])) {
            throw new \RuntimeException(sprintf('Invalid TCA for table "%s": ctrl.%s points to missing column "%s".', $table, $field, $fieldName));
        }

        return $fieldName;
    }

    private function resolveFilter(string $fqcn): FilterInterface
    {
        if ($this->filterMap === null) {
            $this->filterMap = [];
            foreach ($this->filterHandlers as $filter) {
                $this->filterMap[$filter::class] = $filter;
            }
        }

        return $this->filterMap[$fqcn] ?? throw new \InvalidArgumentException(
            sprintf('No filter registered for class "%s".', $fqcn),
        );
    }
}
