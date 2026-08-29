<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\DataAccess;

use MaikSchneider\TcaApi\Configuration\ApiDefinition;
use MaikSchneider\TcaApi\Registry\ApiRegistry;
use MaikSchneider\TcaApi\Security\AccessController;
use MaikSchneider\TcaApi\Validation\FieldValidator;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\MathUtility;
use TYPO3\CMS\Core\Utility\StringUtility;

/**
 * Pre-processes a write request body to resolve relation fields.
 *
 * Supports three input forms per relation column:
 *   - Integer UID  → kept as-is (references existing record)
 *   - Assoc array  → prepared as a NEW_xxx placeholder entry in extraDataMap
 *   - Sequential array mixing both forms (mixed list)
 *
 * The caller merges extraDataMap into the DataHandler processDataMap call so
 * ALL records (parent + related) are created in a single DataHandler run.
 *
 * ── Inline (type=inline + foreign_field) ────────────────────────────────────
 * New child objects are put in extraDataMap under their own NEW_xxx keys.
 * The parent's inline column is set to the comma-separated list of child NEW_xxx
 * placeholders. DataHandler's checkValueForInline() detects the NEW_xxx values
 * and defers processing to the remapStack. After all records are created,
 * processRemapStack() resolves child placeholders to real UIDs and calls
 * RelationHandler::writeForeignField() which sets foreign_field = parentUid on
 * each child directly via SQL — no type-checking, works for passthrough fields.
 * Children must NOT have foreign_field pre-set; writeForeignField handles it.
 *
 * ── Non-inline relations (select, category, group) ──────────────────────────
 * New objects become NEW_xxx entries in extraDataMap; their placeholder is placed
 * in the parent's field value. DataHandler's checkValueForGroupFolderSelect() and
 * checkValueForCategory() detect NEW_xxx values and defer processing to the
 * remapStack — the same mechanism as inline. processRemapStack() resolves
 * placeholders to real UIDs and calls the respective processDBdata handler.
 *
 * Security gate: object creation is only allowed for foreign tables that have
 * an entry in ApiRegistry. A nested object for an unregistered table is rejected
 * with an UNRESOLVABLE_RELATION violation rather than dropped from the write.
 *
 * Child security + validation gate: before creating any nested child record,
 * the child resource's security['create'] role is checked via AccessController,
 * and the child data is validated via FieldValidator against the child config.
 * Failures are collected in ResolvedInput::$violations; the caller must check
 * for violations and reject the request (422) before calling processDataMap.
 */
#[Autoconfigure(public: true)]
final readonly class RelationInputResolver
{
    public function __construct(
        private AccessController $accessController,
        private FieldValidator $fieldValidator,
        private ApiRegistry $apiRegistry,
    ) {
    }

    /**
     * @param array $body  Raw decoded request body
     * @param ApiDefinition $parentDefinition Parent resource definition
     * @param int $pid Storage PID for created sub-records
     * @param ServerRequestInterface $request Current HTTP request (used for child security checks)
     */
    public function resolve(
        array $body,
        ApiDefinition $parentDefinition,
        int $pid,
        ServerRequestInterface $request,
    ): ResolvedInput {
        $table = $parentDefinition->table;
        $feUser       = $request->getAttribute('frontend.user');
        $feUserRow    = $feUser?->user;
        $apiPrefix    = rtrim((string)$request->getAttribute('tca_api.api_prefix', '/_api'), '/');
        $scalarBody   = [];
        $extraDataMap = [];
        $violations   = [];

        foreach ($body as $col => $value) {
            $tcaConfig = $GLOBALS['TCA'][$table]['columns'][$col]['config'] ?? null;

            // Not a known TCA column or already scalar → pass through.
            // IRI strings (e.g. "/_api/sys-categories/1") on relation columns are resolved to integer UIDs.
            if ($tcaConfig === null || !is_array($value)) {
                if (is_string($value) && $tcaConfig !== null && ($tcaConfig['foreign_table'] ?? '') !== '') {
                    $value = $this->iriToUid($value, $apiPrefix) ?? $value;
                }
                $scalarBody[$col] = $value;
                continue;
            }

            $type         = $tcaConfig['type'] ?? '';
            $foreignTable = $tcaConfig['foreign_table'] ?? '';
            $foreignField = $tcaConfig['foreign_field'] ?? '';

            // ── inline + foreign_field ─────────────────────────────────────────
            // New objects become NEW_xxx entries in extraDataMap. The parent's
            // inline column is set to the child placeholders so DataHandler's
            // checkValueForInline() defers processing to processRemapStack(), which
            // resolves placeholders and calls writeForeignField() to set
            // foreign_field = parentUid on each child via direct SQL.
            // Children must NOT have foreign_field pre-set.
            if ($type === 'inline' && $foreignField !== '' && $foreignTable !== '') {
                $newObjects = $this->extractNewObjects($value);
                if ($newObjects === []) {
                    continue;
                }

                $subConfig = $this->resolveChildConfig($foreignTable, $col, $parentDefinition);
                if ($subConfig === null) {
                    $violations[] = $this->unresolvableRelation($col, $foreignTable, null);
                    continue;
                }

                $childPlaceholders = [];
                foreach ($newObjects as $index => $childData) {
                    // Child security check
                    $childViolation = $this->checkChildSecurity($subConfig, $request, $col, $index);
                    if ($childViolation !== null) {
                        $violations[] = $childViolation;
                        continue;
                    }

                    // Child validation check
                    $childViolations = $this->validateChildData($childData, $subConfig, $col, $index);
                    if ($childViolations !== []) {
                        array_push($violations, ...$childViolations);
                        continue;
                    }

                    $ph                              = StringUtility::getUniqueId('NEW');
                    $childData                       = $this->prepareChildData($childData, $pid, $feUserRow, $subConfig);
                    $extraDataMap[$foreignTable][$ph] = $childData;
                    $childPlaceholders[]             = $ph;
                }

                if ($childPlaceholders !== []) {
                    // Inline column carries child placeholders; DataHandler's remap
                    // stack resolves them and calls writeForeignField() after all
                    // records are created (works for foreign_field = passthrough too).
                    $scalarBody[$col] = implode(',', $childPlaceholders);
                }
                continue;
            }

            // ── Single assoc-array value (hasOne: select + foreign_table) ─────
            // New object becomes a NEW_xxx entry in extraDataMap; its placeholder
            // is placed in the parent's field. DataHandler's remapStack resolves it.
            if (!array_is_list($value)) {
                if ($foreignTable !== '') {
                    $subConfig = $this->resolveChildConfig($foreignTable, $col, $parentDefinition);
                    if ($subConfig !== null) {
                        // Child security check
                        $childViolation = $this->checkChildSecurity($subConfig, $request, $col, null);
                        if ($childViolation !== null) {
                            $violations[] = $childViolation;
                            continue;
                        }

                        // Child validation check
                        $childViolations = $this->validateChildData($value, $subConfig, $col, null);
                        if ($childViolations !== []) {
                            array_push($violations, ...$childViolations);
                            continue;
                        }

                        $ph                              = StringUtility::getUniqueId('NEW');
                        $extraDataMap[$foreignTable][$ph] = $this->prepareChildData($value, $pid, $feUserRow, $subConfig);
                        $scalarBody[$col]                = $ph;
                    } else {
                        $violations[] = $this->unresolvableRelation($col, $foreignTable, null);
                    }
                    continue;
                }
                // Non-relation assoc array → pass through unchanged
            }

            // ── Sequential array (hasMany: MM, UID-list, category, group) ─────
            // New objects become NEW_xxx entries in extraDataMap; their placeholders
            // are included in the UID list. DataHandler's remapStack resolves them.
            $effectiveFt = $this->effectiveForeignTable($type, $tcaConfig);
            if ($effectiveFt !== '') {
                $subConfig    = $this->resolveChildConfig($effectiveFt, $col, $parentDefinition);
                $resolvedUids = [];
                foreach ($value as $index => $item) {
                    if (is_array($item) && !array_is_list($item) && $subConfig === null) {
                        $violations[] = $this->unresolvableRelation($col, $effectiveFt, $index);
                    } elseif (is_array($item) && !array_is_list($item)) {
                        // Child security check
                        $childViolation = $this->checkChildSecurity($subConfig, $request, $col, $index);
                        if ($childViolation !== null) {
                            $violations[] = $childViolation;
                            continue;
                        }

                        // Child validation check
                        $childViolations = $this->validateChildData($item, $subConfig, $col, $index);
                        if ($childViolations !== []) {
                            array_push($violations, ...$childViolations);
                            continue;
                        }

                        $ph                              = StringUtility::getUniqueId('NEW');
                        $extraDataMap[$effectiveFt][$ph] = $this->prepareChildData($item, $pid, $feUserRow, $subConfig);
                        $resolvedUids[]                  = $ph;
                    } elseif (is_string($item) && ($uid = $this->iriToUid($item, $apiPrefix)) !== null) {
                        $resolvedUids[] = $uid;
                    } elseif (MathUtility::canBeInterpretedAsInteger($item)) {
                        $resolvedUids[] = (int)$item;
                    }
                }
                // ColumnFilterTrait will implode(',', $array) on this
                $scalarBody[$col] = $resolvedUids;
                continue;
            }

            // Default: pass through unchanged
            $scalarBody[$col] = $value;
        }

        return new ResolvedInput($scalarBody, $extraDataMap, $violations);
    }

    /**
     * A nested object on a relation column whose table has no ApiRegistry entry
     * cannot be created. Reporting it is the difference between a 422 naming the
     * column and a 201 whose relation is quietly missing.
     *
     * @return array{propertyPath: string, message: string, code: string}
     */
    private function unresolvableRelation(string $col, string $foreignTable, int|string|null $index): array
    {
        return [
            'propertyPath' => $index !== null ? $col . '.' . $index : $col,
            'message'      => sprintf(
                "Cannot create a nested '%s': table '%s' is not registered as an API resource.",
                $col,
                $foreignTable,
            ),
            'code'         => 'UNRESOLVABLE_RELATION',
        ];
    }

    /**
     * Check whether the current request is allowed to create a child record.
     *
     * @param ApiDefinition $subConfig ApiRegistry entry for the child table
     * @param ServerRequestInterface $request Current request
     * @param string $col Parent column name (used for propertyPath)
     * @param int|string|null $index Array index for array paths, null for hasOne
     * @return array{propertyPath: string, message: string, code: string}|null
     */
    private function checkChildSecurity(ApiDefinition $subConfig, ServerRequestInterface $request, string $col, int|string|null $index): ?array
    {
        $requiredRole = $subConfig->securityRole('create');
        if ($this->accessController->isAllowed($requiredRole, $request)) {
            return null;
        }

        $path = $index !== null ? $col . '.' . $index : $col;
        return [
            'propertyPath' => $path,
            'message'      => "Nested creation of '$col' is not allowed.",
            'code'         => 'CHILD_FORBIDDEN',
        ];
    }

    /**
     * Validate child data against the child resource's config.
     *
     * @param array $childData Raw child data from the request
     * @param ApiDefinition $subConfig ApiRegistry entry for the child table
     * @param string $col Parent column name (used for propertyPath prefix)
     * @param int|string|null $index Array index for array paths, null for hasOne
     * @return list<array{propertyPath: string, message: string, code: string}>
     */
    private function validateChildData(array $childData, ApiDefinition $subConfig, string $col, int|string|null $index): array
    {
        $childViolations = $this->fieldValidator->validate($childData, $subConfig);
        if ($childViolations === []) {
            return [];
        }

        $prefix = $index !== null ? $col . '.' . $index . '.' : $col . '.';
        $result = [];
        foreach ($childViolations as $v) {
            $result[] = [
                'propertyPath' => $prefix . $v['propertyPath'],
                'message'      => $v['message'],
                'code'         => $v['code'],
            ];
        }
        return $result;
    }

    /**
     * Prepare child data: set pid, strip client-provided ownership columns,
     * inject authenticated FE user as owner when sub-resource config has one.
     */
    private function prepareChildData(array $data, int $pid, ?array $feUserRow, ?ApiDefinition $subConfig): array
    {
        $data['pid'] = $pid;

        if ($subConfig === null) {
            return $data;
        }

        $ownerCol = $subConfig->ownershipColumn;
        $trackCol = $subConfig->ownershipSetOnCreate;

        foreach (array_unique(array_filter([$ownerCol, $trackCol])) as $col) {
            unset($data[$col]);
        }

        if ($feUserRow !== null && !empty($feUserRow['uid'])) {
            $feUid = (int)$feUserRow['uid'];
            if ($ownerCol !== null) {
                $data[$ownerCol] = $feUid;
            }
            if ($trackCol !== null && $trackCol !== $ownerCol) {
                $data[$trackCol] = $feUid;
            }
        }

        return $data;
    }

    /**
     * Extract assoc-array items (new-record objects) from a value.
     * Integer UIDs in inline arrays are ignored — they are already linked.
     *
     * @return list<array>
     */
    private function extractNewObjects(mixed $value): array
    {
        $items   = is_array($value) && array_is_list($value) ? $value : [$value];
        $objects = [];
        foreach ($items as $item) {
            if (is_array($item) && !array_is_list($item)) {
                $objects[] = $item;
            }
        }
        return $objects;
    }

    /**
     * Resolve the ApiDefinition for a child relation.
     * Checks the parent column's resourceName first; falls back to getByTable().
     * Throws when resourceName is set but no matching resource is registered.
     */
    private function resolveChildConfig(string $foreignTable, string $columnName, ApiDefinition $parentDefinition): ?ApiDefinition
    {
        $columnDef = $parentDefinition->getColumn($columnName);
        if ($columnDef?->resourceName !== null) {
            $resolved = $this->apiRegistry->get($columnDef->resourceName);
            if ($resolved === null) {
                throw new \InvalidArgumentException(sprintf(
                    "Column '%s' in resource '%s' sets resourceName '%s', but no resource with that name is registered.",
                    $columnName,
                    $parentDefinition->resourceName,
                    $columnDef->resourceName,
                ));
            }
            return $resolved;
        }
        return $this->apiRegistry->getByTable($foreignTable);
    }

    /**
     * Determine the effective foreign table for hasMany relation types.
     * Returns '' when the type cannot be used for sub-record creation.
     */
    private function effectiveForeignTable(string $type, array $tcaConfig): string
    {
        if (($tcaConfig['foreign_table'] ?? '') !== '') {
            return $tcaConfig['foreign_table'];
        }

        if ($type === 'category') {
            return 'sys_category';
        }

        if ($type === 'group') {
            $allowed = GeneralUtility::trimExplode(',', $tcaConfig['allowed'] ?? '', true);

            // Only support single-table group for object creation
            if (count($allowed) === 1) {
                return $allowed[0];
            }
        }

        return '';
    }

    /**
     * Extract a UID integer from a Hydra IRI like "/_api/articles/42".
     * Returns null when $value does not match the expected pattern.
     */
    private function iriToUid(string $value, string $apiPrefix): ?int
    {
        if (!str_starts_with($value, $apiPrefix . '/')) {
            return null;
        }

        $path  = substr($value, \strlen($apiPrefix) + 1);
        $parts = explode('/', $path);

        if (\count($parts) !== 2 || !MathUtility::canBeInterpretedAsInteger($parts[1])) {
            return null;
        }

        return (int)$parts[1];
    }
}
