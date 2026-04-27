<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Configuration;

use MaikSchneider\TcaApi\Cache\CacheDefinition;
use MaikSchneider\TcaApi\Enum\AccessRole;
use MaikSchneider\TcaApi\Enum\WriteMode;
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
     * @param array<string, mixed>            $filters    raw filter definitions, unchanged
     * @param string[]                        $allowedOrder
     * @param array<string, string>           $defaultOrder
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
        public readonly WriteMode $writeMode = WriteMode::ACTING_USER,
        public readonly CacheDefinition $cache = new CacheDefinition(),
    ) {
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

    /** Allowed sort directions in order.default values. */
    private const VALID_SORT_DIRECTIONS = ['asc', 'desc', 'ASC', 'DESC'];

    /**
     * Normalises a raw PHP config array, applies all defaults, and validates required fields.
     *
     * @throws \InvalidArgumentException when required fields are missing or values are invalid.
     */
    public static function fromArray(array $raw): self
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
        foreach ($rawFilters as $filterCol => $filterDef) {
            if (!\is_string($filterCol) || $filterCol === '') {
                throw new \InvalidArgumentException(
                    sprintf('TcaApi config for "%s": filter key must be a non-empty string.', $label),
                );
            }
            // Accepted shapes:
            //   ExactFilter::class                       — simple class-string
            //   [SearchFilter::class, ['columns' => …]]  — class-string + options array
            if (\is_string($filterDef)) {
                continue;
            }
            if (\is_array($filterDef) && \is_string($filterDef[0] ?? null)) {
                if (isset($filterDef[1]) && !\is_array($filterDef[1])) {
                    throw new \InvalidArgumentException(
                        sprintf(
                            'TcaApi config for "%s": filter "%s" options (second element) must be an array.',
                            $label,
                            $filterCol,
                        ),
                    );
                }
                continue;
            }
            throw new \InvalidArgumentException(
                sprintf(
                    'TcaApi config for "%s": filter "%s" must be a class-string or [class-string, options-array].',
                    $label,
                    $filterCol,
                ),
            );
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

        return new self(
            table:                  $general['table'],
            resourceName:           $general['resourceName'],
            resourceType:           $general['resourceType'],
            operations:             $general['operations'] ?? self::READ_OPERATIONS,
            itemsPerPage:           isset($general['itemsPerPage']) ? (int)$general['itemsPerPage'] : null,
            maxItemsPerPage:        isset($general['maxItemsPerPage']) ? (int)$general['maxItemsPerPage'] : null,
            type:                   $generalType,
            storagePid:             isset($general['storagePid']) && MathUtility::canBeInterpretedAsInteger($general['storagePid']) ? (int)$general['storagePid'] : null,
            columns:                $columns,
            security:               $rawSecurity,
            filters:                $rawFilters,
            allowedOrder:           $allowedOrder,
            defaultOrder:           $defaultOrder,
            ownershipColumn:        $ownershipColumn,
            ownershipSetOnCreate:   $ownershipSetOnCreate,
            ownershipBeAdminBypass: (bool)($ownership['beAdminBypass'] ?? true),
            virtualProperties:      $virtualProperties,
            isExplicitMode:         $isExplicitMode,
            writeMode:              $writeMode,
            cache:                  $cache,
        );
    }
}
