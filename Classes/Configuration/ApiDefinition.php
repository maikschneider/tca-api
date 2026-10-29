<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Configuration;

use MaikSchneider\TcaApi\Cache\CacheDefinition;
use MaikSchneider\TcaApi\Enum\AccessRole;
use MaikSchneider\TcaApi\Enum\WriteMode;
use MaikSchneider\TcaApi\Exception\InvalidApiDefinitionException;
use MaikSchneider\TcaApi\Filter\FilterDefinition;
use MaikSchneider\TcaApi\Filter\FilterPreResolvableInterface;
use MaikSchneider\TcaApi\Loader\TcaValidatorDeriver;
use MaikSchneider\TcaApi\Security\Scope;
use MaikSchneider\TcaApi\Tca\RecordTypeFields;
use MaikSchneider\TcaApi\Utility\TcaColumnDiscovery;
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
     * @param list<int|string>|null          $readStoragePids read-side pid constraint;
     *                                                          null = no constraint (read all pages),
     *                                                          non-empty list = WHERE pid IN (...);
     *                                                          a string is a site setting key holding a pid
     * @param string|null                     $storagePidSetting site setting key holding the write pid, when
     *                                                          storagePid is a {$…} placeholder (storagePid is null then)
     * @param string|null                     $recordTypeField the TCA ctrl.type column; set only when recordTypes is set
     * @param list<string>|null               $recordTypes     record types the resource is scoped to;
     *                                                          null = all records regardless of type
     * @param array<string, ColumnDefinition>|null $resolvedColumnMap precomputed result of columnMap() without a type;
     *                                                                null = compute on demand
     * @param bool                            $typeSchemas     whether each record exposes only its record type's columns
     * @param array<string, array<string, ColumnDefinition>>|null $resolvedTypeColumnMaps precomputed columnMap() per record type
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
        private readonly ?array $resolvedColumnMap = null,
        public readonly ?string $recordTypeField = null,
        public readonly ?array $recordTypes = null,
        public readonly bool $typeSchemas = false,
        private readonly ?array $resolvedTypeColumnMaps = null,
        public readonly ?string $storagePidSetting = null,
    ) {
    }

    public function isRecordTypeScoped(): bool
    {
        return $this->recordTypeField !== null && $this->recordTypes !== null;
    }

    /**
     * The record type a single-type resource assigns to every record it creates;
     * null when the resource is unscoped or allows several types.
     */
    public function fixedRecordType(): ?string
    {
        return $this->isRecordTypeScoped() && \count($this->recordTypes) === 1 ? $this->recordTypes[0] : null;
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

    /** Whether an item IRI of this resource dereferences, i.e. it has a show endpoint. */
    public function isAddressable(): bool
    {
        return $this->hasOperation('show');
    }

    /**
     * This resource without its item endpoint: serialized with the same columns, but
     * referenced by uid and embedded without @id, e.g. on a site whose
     * tca_api.allowedResources doesn't include it.
     */
    public function withoutItemEndpoint(): self
    {
        return new self(...['operations' => array_values(array_diff($this->operations, ['show']))] + get_object_vars($this));
    }

    public function isUserInfo(): bool
    {
        return $this->type === 'userinfo';
    }

    /**
     * The columns this resource exposes, keyed by column name.
     *
     * Explicit mode: the declared columns as-is (callers still check isReadable()/isWritable()).
     * Default mode: every exposable TCA column, using the declared column config where set.
     * With type schemas: the exposable columns of $recordType's form in showitem order, or
     * without a type the union over all allowed types (what any record may carry).
     *
     * @return array<string, ColumnDefinition>
     */
    public function columnMap(?string $recordType = null): array
    {
        if ($this->typeSchemas) {
            $typeColumnMaps = $this->typeColumnMaps();
            if ($recordType !== null && isset($typeColumnMaps[$recordType])) {
                return $typeColumnMaps[$recordType];
            }
            return $this->resolvedColumnMap ?? self::unionColumnMap($typeColumnMaps);
        }

        return $this->resolvedColumnMap ?? self::buildColumnMap($this->table, $this->columns, $this->isExplicitMode);
    }

    /**
     * The record type whose columns a row exposes; null when the resource has no type schemas.
     */
    public function recordTypeOf(array $row): ?string
    {
        return $this->typeSchemas ? (string)($row[$this->recordTypeField] ?? '') : null;
    }

    /** @return array<string, array<string, ColumnDefinition>> */
    private function typeColumnMaps(): array
    {
        return $this->resolvedTypeColumnMaps ?? self::buildTypeColumnMaps($this->table, (string)$this->recordTypeField, $this->recordTypes ?? [], $this->columns);
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
     * @param array<string, array<string, array<string, mixed>>> $recordTypeColumns raw configs of declared columns
     *                                                               by record type, replacing "columns" within that
     *                                                               type's column map ({@see TcaValidatorDeriver::deriveRecordTypeColumns()})
     * @throws \InvalidArgumentException when required fields are missing or values are invalid.
     */
    public static function fromArray(array $raw, array $filterMap = [], array $recordTypeColumns = []): self
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
            //   [AccessRole::API_KEY, ['articles:write']]   — enum + required scopes
            //   [MyChecker::class, 'methodName']            — callable [class, method]
            if ($secRole instanceof AccessRole) {
                continue;
            }
            if (\is_array($secRole) && isset($secRole[0])) {
                if ($secRole[0] === AccessRole::API_KEY && !self::areValidScopes($secRole[1] ?? [])) {
                    throw new \InvalidArgumentException(
                        sprintf(
                            'TcaApi config for "%s": security["%s"] requires a list of scopes such as "articles:read", "articles:write" or "*:read".',
                            $label,
                            $secOp,
                        ),
                    );
                }
                // [AccessRole, array<int>] or [AccessRole::API_KEY, list<string>]
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

        [$storagePid, $storagePidSetting] = self::resolveStoragePid($general['storagePid'] ?? null, $label);

        // ── group (OpenAPI tag) ──────────────────────────────────────────
        // Accepted shapes:
        //   'group' => 'Editorial'                                  — tag name only
        //   'group' => ['name' => 'Editorial', 'description' => …]  — tag name + description
        [$group, $groupDescription] = self::resolveGroup($general['group'] ?? null, $label);

        [$recordTypeField, $recordTypes, $recordTypeSchema] = self::resolveRecordTypes($general['recordTypes'] ?? null, $general['table']);
        $typeSchemas    = $recordTypes !== null && !$isExplicitMode && $recordTypeSchema === 'type';
        $typeColumnMaps = $typeSchemas ? self::buildTypeColumnMaps(
            $general['table'],
            (string)$recordTypeField,
            $recordTypes,
            $columns,
            array_map(
                static fn (array $typeColumns): array => array_map(ColumnDefinition::fromArray(...), $typeColumns),
                $recordTypeColumns,
            ),
        ) : null;

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
            readStoragePids:        self::resolveReadStoragePids($general['readStoragePids'] ?? null, $storagePid ?? $storagePidSetting, $label),
            group:                  $group,
            groupDescription:       $groupDescription,
            resolvedColumnMap:      $typeColumnMaps !== null
                ? self::unionColumnMap($typeColumnMaps)
                : self::buildColumnMap($general['table'], $columns, $isExplicitMode),
            recordTypeField:        $recordTypeField,
            recordTypes:            $recordTypes,
            typeSchemas:            $typeSchemas,
            resolvedTypeColumnMaps: $typeColumnMaps,
            storagePidSetting:      $storagePidSetting,
        );
    }

    /**
     * Normalises general.recordTypes against the table's TCA types.
     *
     * Accepted shapes: a list, a comma-separated string, or '*' for every type in
     * $TCA[table]['types'] — or an options array with those under 'include'
     * (default '*') and 'schema' ('type' = columns per record type, the default;
     * 'table' = all table columns). Values are compared as strings because TCA
     * type keys are a mix of strings (CType) and integers (doktype).
     *
     * @return array{0: ?string, 1: ?list<string>, 2: string} type field, allowed types (both null when unset) and schema mode
     */
    private static function resolveRecordTypes(mixed $raw, string $table): array
    {
        if ($raw === null) {
            return [null, null, 'type'];
        }

        if (\is_array($raw) && array_filter(array_keys($raw), \is_string(...)) !== []) {
            $unknown = array_diff(array_keys($raw), ['include', 'schema']);
            if ($unknown !== []) {
                throw new InvalidApiDefinitionException(sprintf(
                    'TcaApi config for "%s": general.recordTypes has unknown option(s) "%s". Allowed: include, schema',
                    $table,
                    implode('", "', $unknown),
                ));
            }
            $schema = $raw['schema'] ?? 'type';
            if (!\in_array($schema, ['type', 'table'], true)) {
                throw new InvalidApiDefinitionException(sprintf(
                    'TcaApi config for "%s": general.recordTypes.schema must be "type" or "table".',
                    $table,
                ));
            }
            [$typeField, $types] = self::resolveRecordTypes($raw['include'] ?? '*', $table);

            return [$typeField, $types, $schema];
        }

        $typeField = $GLOBALS['TCA'][$table]['ctrl']['type'] ?? null;
        if (!\is_string($typeField) || $typeField === '') {
            throw new InvalidApiDefinitionException(sprintf(
                'TcaApi config for "%s": general.recordTypes is set, but the table has no TCA ctrl.type field.',
                $table,
            ));
        }
        if (str_contains($typeField, ':')) {
            throw new InvalidApiDefinitionException(sprintf(
                'TcaApi config for "%s": general.recordTypes does not support foreign-field record types (ctrl.type = "%s").',
                $table,
                $typeField,
            ));
        }

        $definedTypes = array_map('strval', array_keys($GLOBALS['TCA'][$table]['types'] ?? []));

        if ($raw === '*') {
            return [$typeField, $definedTypes, 'type'];
        }

        if (!\is_array($raw) && !\is_string($raw)) {
            throw new InvalidApiDefinitionException(sprintf(
                'TcaApi config for "%s": general.recordTypes must be "*", a comma-separated string or a list.',
                $table,
            ));
        }

        $values = \is_array($raw) ? $raw : explode(',', $raw);
        $types  = [];
        foreach ($values as $value) {
            if (!\is_string($value) && !\is_int($value)) {
                throw new InvalidApiDefinitionException(sprintf(
                    'TcaApi config for "%s": general.recordTypes entries must be strings or integers.',
                    $table,
                ));
            }
            $value = trim((string)$value);
            if ($value === '') {
                continue;
            }
            if (!\in_array($value, $definedTypes, true)) {
                throw new InvalidApiDefinitionException(sprintf(
                    'TcaApi config for "%s": general.recordTypes contains "%s", which is not a type in $TCA[\'%s\'][\'types\']. Defined: %s',
                    $table,
                    $value,
                    $table,
                    implode(', ', $definedTypes),
                ));
            }
            $types[] = $value;
        }

        if ($types === []) {
            throw new InvalidApiDefinitionException(sprintf(
                'TcaApi config for "%s": general.recordTypes must contain at least one record type.',
                $table,
            ));
        }

        return [$typeField, array_values(array_unique($types)), 'type'];
    }

    /**
     * Default-mode column map per record type: the type field, then the type's
     * form fields that are exposable (palettes also carry system fields such as
     * hidden or starttime), using the declared column config where set. The type
     * field is part of every map, as a record carries its type even when its form
     * does not show it.
     *
     * @param list<string>                                     $recordTypes
     * @param array<string, ColumnDefinition>                  $columns
     * @param array<string, array<string, ColumnDefinition>>   $typeColumns declared column configs by record type
     * @return array<string, array<string, ColumnDefinition>>
     */
    private static function buildTypeColumnMaps(string $table, string $recordTypeField, array $recordTypes, array $columns, array $typeColumns = []): array
    {
        $exposable = array_flip(TcaColumnDiscovery::getExposableColumnNames($table));

        $maps = [];
        foreach ($recordTypes as $recordType) {
            $fieldNames = RecordTypeFields::fieldNames($table, $recordType);
            if (!\in_array($recordTypeField, $fieldNames, true)) {
                array_unshift($fieldNames, $recordTypeField);
            }

            $maps[$recordType] = [];
            foreach ($fieldNames as $column) {
                if (isset($exposable[$column])) {
                    $maps[$recordType][$column] = $typeColumns[$recordType][$column] ?? $columns[$column] ?? new ColumnDefinition(groups: null);
                }
            }
        }

        return $maps;
    }

    /**
     * @param array<string, array<string, ColumnDefinition>> $typeColumnMaps
     * @return array<string, ColumnDefinition>
     */
    private static function unionColumnMap(array $typeColumnMaps): array
    {
        $union = [];
        foreach ($typeColumnMaps as $columnMap) {
            $union += $columnMap;
        }

        return $union;
    }

    /**
     * @param array<string, ColumnDefinition> $columns
     * @return array<string, ColumnDefinition>
     */
    private static function buildColumnMap(string $table, array $columns, bool $isExplicitMode): array
    {
        if ($isExplicitMode) {
            return $columns;
        }

        $columnMap = [];
        foreach (TcaColumnDiscovery::getExposableColumnNames($table) as $column) {
            $columnMap[$column] = $columns[$column] ?? new ColumnDefinition(groups: null);
        }

        return $columnMap;
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
     *   - '{$site.setting}'   → 'site.setting', resolved per request by {@see StoragePidResolver}
     *   - empty / no pids, or an entry that is neither → throws (misconfiguration: fail loud)
     *
     * @return list<int|string>|null
     */
    private static function resolveReadStoragePids(mixed $raw, int|string|null $storagePid, string $label): ?array
    {
        if ($raw === null) {
            return $storagePid !== null ? [$storagePid] : null;
        }

        if ($raw === '*') {
            return null;
        }

        $values = \is_array($raw) ? $raw : (\is_bool($raw) ? [$raw] : explode(',', (string)$raw));
        $pids   = [];
        foreach ($values as $value) {
            $value = \is_string($value) ? trim($value) : $value;
            if ($value === '') {
                continue;
            }
            if (!\is_bool($value) && MathUtility::canBeInterpretedAsInteger($value)) {
                $pids[] = (int)$value;
                continue;
            }
            $setting = self::siteSettingKey($value);
            if ($setting === null) {
                throw new \InvalidArgumentException(sprintf(
                    'TcaApi config for "%s": general.readStoragePids entry "%s" is neither an integer pid nor a {$site.setting} placeholder.',
                    $label,
                    \is_string($value) ? $value : get_debug_type($value),
                ));
            }
            $pids[] = $setting;
        }

        if ($pids === []) {
            throw new \InvalidArgumentException(
                'TcaApi config: general.readStoragePids must be "*" or contain at least one integer pid.',
            );
        }

        return array_values(array_unique($pids));
    }

    /**
     * general.storagePid: an integer pid, or a {$site.setting} placeholder resolved per request.
     *
     * @return array{0: ?int, 1: ?string} the pid and the setting key; both null when unset
     */
    private static function resolveStoragePid(mixed $raw, string $label): array
    {
        if ($raw === null) {
            return [null, null];
        }
        if (!\is_bool($raw) && MathUtility::canBeInterpretedAsInteger($raw)) {
            return [(int)$raw, null];
        }

        $setting = self::siteSettingKey($raw);
        if ($setting === null) {
            throw new \InvalidArgumentException(sprintf(
                'TcaApi config for "%s": general.storagePid must be an integer pid or a {$site.setting} placeholder.',
                $label,
            ));
        }

        return [null, $setting];
    }

    /** '{$myext.storagePid}' → 'myext.storagePid'; null for anything else */
    private static function siteSettingKey(mixed $value): ?string
    {
        return \is_string($value) && preg_match('/^\{\$([A-Za-z0-9_.-]+)\}$/', $value, $match) === 1 ? $match[1] : null;
    }

    private static function areValidScopes(mixed $scopes): bool
    {
        if (!\is_array($scopes) || !array_is_list($scopes)) {
            return false;
        }

        return array_filter($scopes, static fn (mixed $scope): bool => !Scope::isValid($scope)) === [];
    }
}
