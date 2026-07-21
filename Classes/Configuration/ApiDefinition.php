<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Configuration;

use MaikSchneider\TcaApi\Cache\CacheDefinition;
use MaikSchneider\TcaApi\Enum\AccessRole;
use MaikSchneider\TcaApi\Enum\WriteMode;
use MaikSchneider\TcaApi\Filter\FilterDefinition;
use MaikSchneider\TcaApi\Filter\FilterPreResolvableInterface;
use TYPO3\CMS\Core\Utility\MathUtility;

/**
 * Typed, normalised API endpoint configuration.
 *
 * Created by ApiDefinition::fromArray() from a raw PHP config array.
 * All defaults are applied in fromArray() — no consumer needs to know defaults.
 *
 * Stored in ApiRegistry instead of raw arrays.
 */
final readonly class ApiDefinition
{
    /**
     * @param string[]                        $operations
     * @param array<string, ColumnDefinition> $columns
     * @param array<string, ColumnDefinition> $virtualProperties
     * @param array<string, mixed>            $security   keyed by operation name
     * @param array<string, FilterDefinition>  $filters    normalised filter definitions
     * @param string[]                        $allowedOrder
     * @param array<string, string>           $defaultOrder
     * @param int[]|null                      $readStoragePids resolved read-side pid constraint;
     *                                                          null = no constraint (read all pages),
     *                                                          non-empty list = WHERE pid IN (...)
     */
    public function __construct(
        public readonly string $table,
        public readonly string $resourceName,
        public readonly string $resourceType,
        public readonly array $operations,
        public readonly ?int $itemsPerPage,
        public readonly ?int $maxItemsPerPage,
        public readonly ?string $type,
        public readonly ?int $storagePid,
        public readonly array $columns,
        public readonly array $security,
        public readonly array $filters,
        public readonly array $allowedOrder,
        public readonly array $defaultOrder,
        public readonly ?string $ownershipColumn,
        public readonly ?string $ownershipSetOnCreate,
        public readonly bool $ownershipBeAdminBypass,
        public readonly array $virtualProperties,
        public readonly bool $isExplicitMode,
        public readonly string $languageMode = 'auto',
        public readonly WriteMode $writeMode = WriteMode::ACTING_USER,
        public readonly CacheDefinition $cache = new CacheDefinition(),
        public readonly ?array $readStoragePids = null,
        public readonly ?string $group = null,
        public readonly ?string $groupDescription = null,
    ) {
    }

    /**
     * OpenAPI tag under which this resource's operations are grouped in Swagger UI.
     *
     * Falls back to the resourceType when no explicit group is configured, so every
     * resource gets its own section out of the box instead of a single "default" bucket.
     */
    public function tagName(): string
    {
        return $this->group ?? $this->resourceType;
    }

    /**
     * Returns the required access role for the given operation.
     *
     * Default when the operation is not explicitly configured in security:
     *   list, show → AccessRole::PUBLIC  (read operations are public by default)
     *   create, update, delete → AccessRole::DISABLED  (write operations require explicit config)
     */
    public function securityRole(string $operation): mixed
    {
        return $this->security[$operation]
            ?? (\in_array($operation, self::READ_OPERATIONS, true) ? AccessRole::PUBLIC : AccessRole::DISABLED);
    }

    public function hasOperation(string $operation): bool
    {
        return \in_array($operation, $this->operations, true);
    }

    public function isUserInfo(): bool
    {
        return $this->type === 'userinfo';
    }

    public function getColumn(string $name): ?ColumnDefinition
    {
        return $this->columns[$name] ?? null;
    }

    public function getVirtualProperty(string $name): ?ColumnDefinition
    {
        return $this->virtualProperties[$name] ?? null;
    }

    /** Valid operation identifiers used across operations, groups, and security keys. */
    private const VALID_OPERATIONS = ['list', 'show', 'create', 'update', 'delete'];

    /** Read operations that default to PUBLIC when not explicitly configured in security. */
    private const READ_OPERATIONS = ['list', 'show'];

    /** Allowed values for general.type. */
    private const VALID_GENERAL_TYPES = ['userinfo'];

    /** Allowed values for general.language.mode. */
    private const VALID_LANGUAGE_MODES = ['auto', 'ignore'];

    /** Allowed sort directions in order.default values. */
    private const VALID_SORT_DIRECTIONS = ['asc', 'desc', 'ASC', 'DESC'];

    /**
     * Normalises a raw PHP config array, applies all defaults, and validates required fields.
     *
     * @param array<string, FilterPreResolvableInterface> $filterMap DI-managed filter instances keyed by FQCN,
     *                                                               forwarded to FilterDefinition::fromRaw() so that
     *                                                               pre-resolution runs during DTO construction.
     *                                                               Omit (or pass []) when no DI context is available
     *                                                               (e.g. unit tests) — apply() fallbacks handle it.
     * @throws \InvalidArgumentException when required fields are missing or values are invalid.
     */
    public static function fromArray(array $raw, array $filterMap = []): self
    {
        // ── general section (required) ──────────────────────────────────
        $general = $raw['general'] ?? [];
        if (!\is_array($general)) {
            throw new \InvalidArgumentException('TcaApi config "general" must be an array.');
        }

        foreach (['table', 'resourceName', 'resourceType'] as $key) {
            if (!isset($general[$key]) || !\is_string($general[$key]) || $general[$key] === '') {
                throw new \InvalidArgumentException(
                    sprintf('TcaApi config is missing required general.%s (must be a non-empty string).', $key),
                );
            }
        }

        $label = $general['table']; // for error messages

        // ── operations ──────────────────────────────────────────────────
        if (isset($general['operations']) && !\is_array($general['operations'])) {
            throw new \InvalidArgumentException(
                sprintf('TcaApi config for "%s": general.operations must be an array.', $label),
            );
        }
        foreach ($general['operations'] ?? [] as $op) {
            if (!\is_string($op) || !\in_array($op, self::VALID_OPERATIONS, true)) {
                throw new \InvalidArgumentException(
                    sprintf(
                        'TcaApi config for "%s": general.operations contains invalid value "%s". Allowed: %s',
                        $label,
                        \is_string($op) ? $op : \get_debug_type($op),
                        implode(', ', self::VALID_OPERATIONS),
                    ),
                );
            }
        }

        // ── itemsPerPage / maxItemsPerPage ──────────────────────────────
        foreach (['itemsPerPage', 'maxItemsPerPage'] as $paginationKey) {
            if (isset($general[$paginationKey])) {
                $val = $general[$paginationKey];
                if (!\is_int($val) || $val < 1) {
                    throw new \InvalidArgumentException(
                        sprintf('TcaApi config for "%s": general.%s must be a positive integer.', $label, $paginationKey),
                    );
                }
            }
        }

        // ── type ────────────────────────────────────────────────────────
        $generalType = $general['type'] ?? null;
        if ($generalType !== null) {
            if (!\is_string($generalType) || !\in_array($generalType, self::VALID_GENERAL_TYPES, true)) {
                throw new \InvalidArgumentException(
                    sprintf(
                        'TcaApi config for "%s": general.type has invalid value "%s". Allowed: %s',
                        $label,
                        \is_string($generalType) ? $generalType : \get_debug_type($generalType),
                        implode(', ', self::VALID_GENERAL_TYPES),
                    ),
                );
            }
        }

        // ── language ───────────────────────────────────────────────────
        $language = $general['language'] ?? [];
        if (!\is_array($language)) {
            throw new \InvalidArgumentException(
                sprintf('TcaApi config for "%s": general.language must be an array.', $label),
            );
        }
        $languageMode = $language['mode'] ?? 'auto';
        if (!\is_string($languageMode) || !\in_array($languageMode, self::VALID_LANGUAGE_MODES, true)) {
            throw new \InvalidArgumentException(
                sprintf(
                    'TcaApi config for "%s": general.language.mode has invalid value "%s". Allowed: %s',
                    $label,
                    \is_string($languageMode) ? $languageMode : \get_debug_type($languageMode),
                    implode(', ', self::VALID_LANGUAGE_MODES),
                ),
            );
        }

        // ── columns ─────────────────────────────────────────────────────
        $rawColumns = $raw['columns'] ?? [];
        if (!\is_array($rawColumns)) {
            throw new \InvalidArgumentException(
                sprintf('TcaApi config for "%s": "columns" must be an array.', $label),
            );
        }
        $columns = [];
        foreach ($rawColumns as $name => $col) {
            if (!\is_string($name) || $name === '') {
                throw new \InvalidArgumentException(
                    sprintf('TcaApi config for "%s": column key must be a non-empty string.', $label),
                );
            }
            if (!\is_array($col)) {
                throw new \InvalidArgumentException(
                    sprintf('TcaApi config for "%s": column "%s" definition must be an array.', $label, $name),
                );
            }
            try {
                $columns[$name] = ColumnDefinition::fromArray($col);
            } catch (\InvalidArgumentException $e) {
                throw new \InvalidArgumentException(
                    sprintf('TcaApi config for "%s", column "%s": %s', $label, $name, $e->getMessage()),
                    0,
                    $e,
                );
            }
        }

        $isExplicitMode = false;
        foreach ($columns as $col) {
            if ($col->hasGroups()) {
                $isExplicitMode = true;
                break;
            }
        }

        // ── virtualProperties ───────────────────────────────────────────
        $rawVirtualProperties = $raw['virtualProperties'] ?? [];
        if (!\is_array($rawVirtualProperties)) {
            throw new \InvalidArgumentException(
                sprintf('TcaApi config for "%s": "virtualProperties" must be an array.', $label),
            );
        }
        $virtualProperties = [];
        foreach ($rawVirtualProperties as $name => $vp) {
            if (!\is_string($name) || $name === '') {
                throw new \InvalidArgumentException(
                    sprintf('TcaApi config for "%s": virtualProperties key must be a non-empty string.', $label),
                );
            }
            if (!\is_array($vp)) {
                throw new \InvalidArgumentException(
                    sprintf('TcaApi config for "%s": virtualProperty "%s" definition must be an array.', $label, $name),
                );
            }
            try {
                $virtualProperties[$name] = ColumnDefinition::fromArray($vp);
            } catch (\InvalidArgumentException $e) {
                throw new \InvalidArgumentException(
                    sprintf('TcaApi config for "%s", virtualProperty "%s": %s', $label, $name, $e->getMessage()),
                    0,
                    $e,
                );
            }
        }

        // ── security ────────────────────────────────────────────────────
        $rawSecurity = $raw['security'] ?? [];
        if (!\is_array($rawSecurity)) {
            throw new \InvalidArgumentException(
                sprintf('TcaApi config for "%s": "security" must be an array.', $label),
            );
        }
        foreach ($rawSecurity as $secOp => $secRole) {
            if (!\in_array($secOp, self::VALID_OPERATIONS, true)) {
                throw new \InvalidArgumentException(
                    sprintf(
                        'TcaApi config for "%s": security key "%s" is not a valid operation. Allowed: %s',
                        $label,
                        $secOp,
                        implode(', ', self::VALID_OPERATIONS),
                    ),
                );
            }
            // Accepted shapes:
            //   AccessRole::PUBLIC                           — simple enum
            //   [AccessRole::FE_GROUP, [1, 2]]              — enum + group IDs
            //   [MyChecker::class, 'methodName']            — callable [class, method]
            if ($secRole instanceof AccessRole) {
                continue;
            }
            if (\is_array($secRole) && isset($secRole[0])) {
                // [AccessRole, array<int>]
                if ($secRole[0] instanceof AccessRole) {
                    continue;
                }
                // [class-string, method-string]
                if (\is_string($secRole[0]) && \is_string($secRole[1] ?? null)) {
                    continue;
                }
            }
            throw new \InvalidArgumentException(
                sprintf(
                    'TcaApi config for "%s": security["%s"] must be an AccessRole enum, '
                    . '[AccessRole, groupIds] tuple, or [class-string, method-string] callable.',
                    $label,
                    $secOp,
                ),
            );
        }

        // ── filters ─────────────────────────────────────────────────────
        $rawFilters = $raw['filters'] ?? [];
        if (!\is_array($rawFilters)) {
            throw new \InvalidArgumentException(
                sprintf('TcaApi config for "%s": "filters" must be an array.', $label),
            );
        }
        $filters = [];
        foreach ($rawFilters as $filterCol => $filterDef) {
            if (!\is_string($filterCol) || $filterCol === '') {
                throw new \InvalidArgumentException(
                    sprintf('TcaApi config for "%s": filter key must be a non-empty string.', $label),
                );
            }
            try {
                $filters[$filterCol] = FilterDefinition::fromRaw($label, $filterCol, $filterDef, $filterMap);
            } catch (\InvalidArgumentException $e) {
                throw new \InvalidArgumentException(
                    sprintf('TcaApi config for "%s": %s', $label, $e->getMessage()),
                    0,
                    $e,
                );
            }
        }

        // ── order ───────────────────────────────────────────────────────
        $order = $raw['order'] ?? [];
        if (!\is_array($order)) {
            throw new \InvalidArgumentException(
                sprintf('TcaApi config for "%s": "order" must be an array.', $label),
            );
        }

        $allowedOrder = $order['allowed'] ?? [];
        if (!\is_array($allowedOrder)) {
            throw new \InvalidArgumentException(
                sprintf('TcaApi config for "%s": order.allowed must be an array of column name strings.', $label),
            );
        }
        foreach ($allowedOrder as $ao) {
            if (!\is_string($ao) || $ao === '') {
                throw new \InvalidArgumentException(
                    sprintf('TcaApi config for "%s": order.allowed entries must be non-empty strings.', $label),
                );
            }
        }

        $defaultOrder = $order['default'] ?? [];
        if (!\is_array($defaultOrder)) {
            throw new \InvalidArgumentException(
                sprintf('TcaApi config for "%s": order.default must be an associative array.', $label),
            );
        }
        foreach ($defaultOrder as $orderCol => $orderDir) {
            if (!\is_string($orderCol) || $orderCol === '') {
                throw new \InvalidArgumentException(
                    sprintf('TcaApi config for "%s": order.default key must be a non-empty string.', $label),
                );
            }
            if (!\is_string($orderDir) || !\in_array($orderDir, self::VALID_SORT_DIRECTIONS, true)) {
                throw new \InvalidArgumentException(
                    sprintf(
                        'TcaApi config for "%s": order.default["%s"] must be "asc" or "desc".',
                        $label,
                        $orderCol,
                    ),
                );
            }
        }

        // ── ownership ───────────────────────────────────────────────────
        $ownership = $raw['ownership'] ?? [];
        if (!\is_array($ownership)) {
            throw new \InvalidArgumentException(
                sprintf('TcaApi config for "%s": "ownership" must be an array.', $label),
            );
        }
        $ownershipColumn = $ownership['column'] ?? null;
        if ($ownershipColumn !== null && (!\is_string($ownershipColumn) || $ownershipColumn === '')) {
            throw new \InvalidArgumentException(
                sprintf('TcaApi config for "%s": ownership.column must be a non-empty string.', $label),
            );
        }
        $ownershipSetOnCreate = $ownership['setOnCreate'] ?? null;
        if ($ownershipSetOnCreate !== null && (!\is_string($ownershipSetOnCreate) || $ownershipSetOnCreate === '')) {
            throw new \InvalidArgumentException(
                sprintf('TcaApi config for "%s": ownership.setOnCreate must be a non-empty string.', $label),
            );
        }

        // ── cache ────────────────────────────────────────────────────────
        $rawCache = $raw['cache'] ?? [];
        if (!\is_array($rawCache)) {
            throw new \InvalidArgumentException(
                sprintf('TcaApi config for "%s": "cache" must be an array.', $label),
            );
        }
        try {
            $cache = CacheDefinition::fromArray($rawCache);
        } catch (\InvalidArgumentException $e) {
            throw new \InvalidArgumentException(
                sprintf('TcaApi config for "%s": %s', $label, $e->getMessage()),
                0,
                $e,
            );
        }

        // ── writeMode ───────────────────────────────────────────────────
        $rawWriteMode = $general['writeMode'] ?? 'acting_user';
        if (!\is_string($rawWriteMode)) {
            throw new \InvalidArgumentException(
                sprintf('TcaApi config for "%s": general.writeMode must be a string.', $label),
            );
        }
        $writeMode = WriteMode::tryFrom($rawWriteMode);
        if ($writeMode === null) {
            throw new \InvalidArgumentException(
                sprintf('Invalid writeMode "%s" in TcaApi config for %s. Valid values: acting_user, system_admin', $rawWriteMode, $label),
            );
        }

        $storagePid = isset($general['storagePid']) && MathUtility::canBeInterpretedAsInteger($general['storagePid'])
            ? (int)$general['storagePid']
            : null;

        // ── group (OpenAPI tag) ──────────────────────────────────────────
        // Accepted shapes:
        //   'group' => 'Editorial'                                  — tag name only
        //   'group' => ['name' => 'Editorial', 'description' => …]  — tag name + description
        [$group, $groupDescription] = self::resolveGroup($general['group'] ?? null, $label);

        return new self(
            table:                  $general['table'],
            resourceName:           $general['resourceName'],
            resourceType:           $general['resourceType'],
            operations:             $general['operations'] ?? self::READ_OPERATIONS,
            itemsPerPage:           isset($general['itemsPerPage']) ? (int)$general['itemsPerPage'] : null,
            maxItemsPerPage:        isset($general['maxItemsPerPage']) ? (int)$general['maxItemsPerPage'] : null,
            type:                   $generalType,
            storagePid:             $storagePid,
            columns:                $columns,
            security:               $rawSecurity,
            filters:                $filters,
            allowedOrder:           $allowedOrder,
            defaultOrder:           $defaultOrder,
            ownershipColumn:        $ownershipColumn,
            ownershipSetOnCreate:   $ownershipSetOnCreate,
            ownershipBeAdminBypass: (bool)($ownership['beAdminBypass'] ?? true),
            virtualProperties:      $virtualProperties,
            isExplicitMode:         $isExplicitMode,
            languageMode:           $languageMode,
            writeMode:              $writeMode,
            cache:                  $cache,
            readStoragePids:        self::resolveReadStoragePids($general['readStoragePids'] ?? null, $storagePid),
            group:                  $group,
            groupDescription:       $groupDescription,
        );
    }

    /**
     * Normalises the general.group config into a [name, description] pair.
     *
     * @return array{0: ?string, 1: ?string} tag name and optional description (both null when unset)
     */
    private static function resolveGroup(mixed $raw, string $label): array
    {
        if ($raw === null) {
            return [null, null];
        }

        if (\is_string($raw)) {
            if ($raw === '') {
                throw new \InvalidArgumentException(
                    sprintf('TcaApi config for "%s": general.group must be a non-empty string.', $label),
                );
            }

            return [$raw, null];
        }

        if (\is_array($raw)) {
            $name = $raw['name'] ?? null;
            if (!\is_string($name) || $name === '') {
                throw new \InvalidArgumentException(
                    sprintf('TcaApi config for "%s": general.group.name must be a non-empty string.', $label),
                );
            }
            $description = $raw['description'] ?? null;
            if ($description !== null && (!\is_string($description) || $description === '')) {
                throw new \InvalidArgumentException(
                    sprintf('TcaApi config for "%s": general.group.description must be a non-empty string.', $label),
                );
            }

            return [$name, $description];
        }

        throw new \InvalidArgumentException(
            sprintf(
                'TcaApi config for "%s": general.group must be a string or an array with a "name" (and optional "description").',
                $label,
            ),
        );
    }

    /**
     * Resolves the read-side pid constraint.
     *
     * Returns null when reads should not be pid-constrained, or a non-empty list of pids
     * to constrain reads with `pid IN (...)`.
     *
     *   - key absent          → fall back to the write target ([storagePid], or null if unset)
     *   - '*'                 → null (read from all pages, regardless of the write target)
     *   - CSV string "1,2,3"  → [1, 2, 3]
     *   - array [1, 2, 3]     → [1, 2, 3]
     *   - empty / no integers → throws (misconfiguration: fail loud, never silently match nothing)
     */
    private static function resolveReadStoragePids(mixed $raw, ?int $storagePid): ?array
    {
        if ($raw === null) {
            return $storagePid !== null ? [$storagePid] : null;
        }

        if ($raw === '*') {
            return null;
        }

        $values = \is_array($raw) ? $raw : explode(',', (string)$raw);
        $pids   = [];
        foreach ($values as $value) {
            $value = \is_string($value) ? trim($value) : $value;
            if (MathUtility::canBeInterpretedAsInteger($value)) {
                $pids[] = (int)$value;
            }
        }

        if ($pids === []) {
            throw new \InvalidArgumentException(
                'TcaApi config: general.readStoragePids must be "*" or contain at least one integer pid.',
            );
        }

        return array_values(array_unique($pids));
    }
}
