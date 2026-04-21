<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Configuration;

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
        public readonly mixed $storagePid,
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
    ) {
    }

    /**
     * Returns the required access role for the given operation.
     * Falls back to AccessRole::PUBLIC when the operation is not configured.
     */
    public function securityRole(string $operation): mixed
    {
        return $this->security[$operation] ?? AccessRole::PUBLIC;
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

    public function getStoragePid(): int
    {
        return MathUtility::canBeInterpretedAsInteger($this->storagePid) ? (int)$this->storagePid : 0;
    }

    /**
     * Normalises a raw PHP config array, applies all defaults, and validates required fields.
     *
     * @throws \InvalidArgumentException when required fields are missing.
     */
    public static function fromArray(array $raw): self
    {
        $general = $raw['general'] ?? [];

        foreach (['table', 'resourceName', 'resourceType'] as $key) {
            if (!isset($general[$key]) || $general[$key] === '') {
                throw new \InvalidArgumentException(
                    sprintf('TcaApi config is missing required general.%s', $key),
                );
            }
        }
        if (!\array_key_exists('operations', $general)) {
            throw new \InvalidArgumentException('TcaApi config is missing required general.operations');
        }

        $columns = [];
        foreach ($raw['columns'] ?? [] as $name => $col) {
            $columns[$name] = ColumnDefinition::fromArray($col);
        }

        $isExplicitMode = false;
        foreach ($columns as $col) {
            if ($col->hasGroups()) {
                $isExplicitMode = true;
                break;
            }
        }

        $virtualProperties = [];
        foreach ($raw['virtualProperties'] ?? [] as $name => $vp) {
            $virtualProperties[$name] = ColumnDefinition::fromArray($vp);
        }

        $ownership = $raw['ownership'] ?? [];
        $order     = $raw['order'] ?? [];

        // Parse write mode configuration. Default: ACTING_USER (respects user identity).
        // Opt-in: 'system_admin' for trusted internal APIs that bypass user context.
        $rawWriteMode = $general['writeMode'] ?? 'acting_user';
        $writeMode = WriteMode::tryFrom($rawWriteMode);
        if ($writeMode === null) {
            throw new \InvalidArgumentException(
                sprintf('Invalid writeMode "%s" in TcaApi config for %s. Valid values: acting_user, system_admin', $rawWriteMode, $general['table']),
            );
        }

        return new self(
            table:                  $general['table'],
            resourceName:           $general['resourceName'],
            resourceType:           $general['resourceType'],
            operations:             $general['operations'],
            itemsPerPage:           isset($general['itemsPerPage']) ? (int)$general['itemsPerPage'] : null,
            maxItemsPerPage:        isset($general['maxItemsPerPage']) ? (int)$general['maxItemsPerPage'] : null,
            type:                   $general['type'] ?? null,
            storagePid:             $general['storagePid'] ?? null,
            columns:                $columns,
            security:               $raw['security'] ?? [],
            filters:                $raw['filters'] ?? [],
            allowedOrder:           $order['allowed'] ?? [],
            defaultOrder:           $order['default'] ?? [],
            ownershipColumn:        $ownership['column'] ?? null,
            ownershipSetOnCreate:   $ownership['setOnCreate'] ?? null,
            ownershipBeAdminBypass: (bool)($ownership['beAdminBypass'] ?? true),
            virtualProperties:      $virtualProperties,
            isExplicitMode:         $isExplicitMode,
            writeMode:              $writeMode,
        );
    }
}
