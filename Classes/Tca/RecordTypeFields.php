<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tca;

/**
 * Reads the form of a TCA record type: which columns it shows and their
 * configuration with the type's columnsOverrides applied.
 *
 * Mirrors TcaSchemaBuilder::findRelevantFieldsForSubSchema() and
 * getFinalFieldConfiguration(), which are private in core. It reads
 * $GLOBALS['TCA'] because it runs while definitions are built at boot, where
 * TcaSchemaFactory is not guaranteed to be available.
 */
final class RecordTypeFields
{
    /**
     * Columns of the type's form in showitem order, palettes unpacked and tabs skipped.
     *
     * @return list<string>
     */
    public static function fieldNames(string $table, string $type): array
    {
        $tca = $GLOBALS['TCA'][$table] ?? [];
        $showItem = $tca['types'][$type]['showitem'] ?? '';
        if (!\is_string($showItem)) {
            return [];
        }

        $fields = [];
        foreach (self::items($showItem) as [$fieldName, $paletteName]) {
            if ($fieldName === '--palette--') {
                $paletteShowItem = $tca['palettes'][$paletteName]['showitem'] ?? null;
                if (!\is_string($paletteShowItem)) {
                    continue;
                }
                foreach (self::items($paletteShowItem) as [$paletteField]) {
                    if (isset($tca['columns'][$paletteField])) {
                        $fields[$paletteField] = true;
                    }
                }
                continue;
            }
            if (isset($tca['columns'][$fieldName])) {
                $fields[$fieldName] = true;
            }
        }

        return array_keys($fields);
    }

    /**
     * The column's TCA definition, with the type's columnsOverrides applied when
     * $type is given.
     *
     * @return array<string, mixed>
     */
    public static function columnDefinition(string $table, string $column, ?string $type = null): array
    {
        $definition = $GLOBALS['TCA'][$table]['columns'][$column] ?? [];
        if (!\is_array($definition)) {
            return [];
        }

        $overrides = $type !== null ? ($GLOBALS['TCA'][$table]['types'][$type]['columnsOverrides'][$column] ?? null) : null;

        return \is_array($overrides) ? array_replace_recursive($definition, $overrides) : $definition;
    }

    /**
     * Splits a showitem string into [fieldName, paletteName] pairs, skipping tabs.
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function items(string $showItem): array
    {
        $items = [];
        foreach (explode(',', $showItem) as $item) {
            $parts = array_map('trim', explode(';', $item));
            $fieldName = $parts[0];
            if ($fieldName === '' || $fieldName === '--div--' || $fieldName === '--linebreak--') {
                continue;
            }
            $items[] = [$fieldName, $parts[2] ?? ''];
        }

        return $items;
    }
}
