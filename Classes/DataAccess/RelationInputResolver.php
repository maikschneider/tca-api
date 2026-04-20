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
 * an entry in ApiRegistry. Objects for unregistered tables are silently skipped.
 *
 * Child security + validation gate: before creating any nested child record,
 * the child resource's security['create'] role is checked via AccessController,
 * and the child data is validated via FieldValidator against the child config.
 * Failures are collected in ResolvedInput::$violations; the caller must check
 * for violations and reject the request (422) before calling processDataMap.
 */
#[Autoconfigure(public: true)]
final class RelationInputResolver
{
    public function __construct(
        private readonly AccessController $accessController,
        private readonly FieldValidator $fieldValidator,
    ) {
    }

    /**
     * @param array $body  Raw decoded request body
     * @param string $table Parent table name
     * @param int $pid Storage PID for created sub-records
     * @param ServerRequestInterface $request Current HTTP request (used for child security checks)
     */
    public function resolve(
        array $body,
        string $table,
        int $pid,
        ServerRequestInterface $request,
    ): ResolvedInput {
        $feUser       = $request->getAttribute('frontend.user');
        $feUserRow    = $feUser?->user;
        $scalarBody   = [];
        $extraDataMap = [];
        $violations   = [];

        foreach ($body as $col => $value) {
            $tcaConfig = $GLOBALS['TCA'][$table]['columns'][$col]['config'] ?? null;

            // Not a known TCA column or already scalar → pass through unchanged
            if ($tcaConfig === null || !is_array($value)) {
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

                $subConfig = ApiRegistry::getByTable($foreignTable);
                // Security gate: skip creation for unregistered foreign tables
                if ($subConfig === null) {
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

                    $ph                              = $this->uniquePlaceholder();
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
                    $subConfig = ApiRegistry::getByTable($foreignTable);
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

                        $ph                              = $this->uniquePlaceholder();
                        $extraDataMap[$foreignTable][$ph] = $this->prepareChildData($value, $pid, $feUserRow, $subConfig);
                        $scalarBody[$col]                = $ph;
                    }
                    // Unregistered foreign table → skip entirely (no scalarBody entry)
                    continue;
                }
                // Non-relation assoc array → pass through unchanged
            }

            // ── Sequential array (hasMany: MM, UID-list, category, group) ─────
            // New objects become NEW_xxx entries in extraDataMap; their placeholders
            // are included in the UID list. DataHandler's remapStack resolves them.
            $effectiveFt = $this->effectiveForeignTable($type, $tcaConfig);
            if ($effectiveFt !== '') {
                $subConfig    = ApiRegistry::getByTable($effectiveFt);
                $resolvedUids = [];
                foreach ($value as $index => $item) {
                    if (MathUtility::canBeInterpretedAsInteger($item)) {
                        $resolvedUids[] = (int)$item;
                    } elseif (is_array($item) && !array_is_list($item) && $subConfig !== null) {
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

                        $ph                              = $this->uniquePlaceholder();
                        $extraDataMap[$effectiveFt][$ph] = $this->prepareChildData($item, $pid, $feUserRow, $subConfig);
                        $resolvedUids[]                  = $ph;
                    }
                    // Unregistered table → skip new-object items
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

    private function uniquePlaceholder(): string
    {
        return 'NEW' . uniqid('', false);
    }
}
