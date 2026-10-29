<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\OpenApi;

use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Utility\MathUtility;

/**
 * JSON schema of a non-relational column whose resource config declares no type,
 * derived from what the serializer sends for its TCA type.
 *
 * Columns without a processor are sent as the database value, so the type and the
 * nullability follow the column TYPO3 creates for the TCA type (DefaultTcaSchema):
 * a check is a SMALLINT, a text column a nullable TEXT, a select with integer items
 * an INT. Columns the serializer formats are described by their format: datetime as
 * an ISO 8601 string or null, link as a URL or null, json, flex and
 * imageManipulation as decoded JSON or null.
 *
 * A column defined differently in an ext_tables.sql can be sent differently; declare
 * its "type" in the resource config then.
 */
final class ColumnValueSchema
{
    /**
     * @param array<string, mixed> $tcaConfig the column's TCA "config"
     * @return array<string, mixed>|null null for a TCA type this class doesn't know
     */
    public static function forTcaConfig(array $tcaConfig): ?array
    {
        $nullable = (bool)($tcaConfig['nullable'] ?? false);

        return match ($tcaConfig['type'] ?? null) {
            'check', 'language' => ['type' => 'integer'],
            'number' => self::nullableIf(
                ['type' => ($tcaConfig['format'] ?? null) === 'decimal' ? 'string' : 'integer'],
                $nullable,
            ),
            'datetime' => self::nullableIf(['type' => 'string', 'format' => 'date-time'], true),
            'link' => self::nullableIf(['type' => 'string'], true),
            'text', 'folder', 'slug' => self::nullableIf(['type' => 'string'], true),
            'country' => self::nullableIf(['type' => 'string'], (new Typo3Version())->getMajorVersion() >= 14 || $nullable),
            'input', 'email', 'color', 'uuid' => self::nullableIf(['type' => 'string'], $nullable),
            // Both use the same decoder: object, list, scalar, null, or the raw
            // string on invalid JSON. Do not reject the serializer's fallback.
            'json', 'imageManipulation' => ['description' => 'Decoded JSON value, or null; raw string if decoding fails'],
            'flex' => self::nullableIf(['type' => 'object'], true),
            'radio' => self::radioSchema($tcaConfig),
            'select' => isset($tcaConfig['foreign_table']) ? null : self::staticSelectSchema($tcaConfig),
            // Any database value; TYPO3 doesn't create these columns.
            'passthrough', 'none', 'user' => ['description' => 'Raw database value'],
            default => null,
        };
    }

    /** @param array<string, mixed> $tcaConfig */
    private static function radioSchema(array $tcaConfig): array
    {
        $items = \is_array($tcaConfig['items'] ?? null) ? $tcaConfig['items'] : [];
        if (self::hasItemsProcFunc($tcaConfig) || $items === [] || !self::itemsAreIntegers($items)) {
            return ['type' => 'string'];
        }

        return ['type' => 'integer'];
    }

    /** @param array<string, mixed> $tcaConfig */
    private static function staticSelectSchema(array $tcaConfig): array
    {
        $items        = \is_array($tcaConfig['items'] ?? null) ? $tcaConfig['items'] : [];
        // TYPO3 14.2 (#105441) made null select items nullable and allowed them
        // alongside integer items. TYPO3 13 stores that list as a non-null string.
        $supportsNullItems = (new Typo3Version())->getMajorVersion() >= 14;
        $containsNull = $supportsNullItems && \in_array(null, array_column($items, 'value'), true);
        $isSingle     = ($tcaConfig['renderType'] ?? '') === 'selectSingle' || ($tcaConfig['maxitems'] ?? 0) === 1;
        $checksItems  = ($tcaConfig['itemsProcFunc'] ?? '') === '' || ($tcaConfig['itemsProcessors'] ?? []) !== [];

        if ($checksItems && $isSingle) {
            $isInteger = $items !== [] && !\is_array($tcaConfig['fileFolderConfig'] ?? false) && self::itemsAreIntegers($items, $supportsNullItems);

            return self::nullableIf(['type' => $isInteger ? 'integer' : 'string'], $containsNull);
        }

        // A comma-separated list of values, nullable for an integer item list.
        if ($checksItems && self::itemsAreIntegers($items, $supportsNullItems)) {
            return self::nullableIf(['type' => 'string'], true);
        }

        return self::nullableIf(['type' => 'string'], (int)($tcaConfig['dbFieldLength'] ?? 0) <= 0);
    }

    /** @param array<array-key, mixed> $items */
    private static function itemsAreIntegers(array $items, bool $allowNull = false): bool
    {
        foreach ($items as $item) {
            $value = \is_array($item) ? ($item['value'] ?? null) : null;
            if (!($allowNull && $value === null) && !MathUtility::canBeInterpretedAsInteger($value)) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $tcaConfig */
    private static function hasItemsProcFunc(array $tcaConfig): bool
    {
        return ($tcaConfig['itemsProcFunc'] ?? '') !== '' || ($tcaConfig['itemsProcessors'] ?? []) !== [];
    }

    private static function nullableIf(array $schema, bool $nullable): array
    {
        return $nullable ? ['oneOf' => [$schema, ['type' => 'null']]] : $schema;
    }
}
