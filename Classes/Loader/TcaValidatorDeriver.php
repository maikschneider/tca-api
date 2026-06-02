<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Loader;

/**
 * Boot-time validator derivation from TCA.
 *
 * Runs once at definition-load time inside {@see ApiDefinitionLoader::load()}.
 * The derived result is baked into the cached ApiDefinition via cache.core,
 * sharing the same lifecycle as filter pre-resolution.
 *
 * Uses $GLOBALS['TCA'] directly — it is fully populated before load() is ever
 * called, whereas TcaSchemaFactory compilation may not have occurred yet at
 * that early boot point.
 *
 * Derivation is strictly gap-fill: when a validator of the same type already
 * exists in the raw column config, the TCA-derived one is skipped for that type.
 * The `required` flag is only injected when the key is completely absent from
 * the raw column config (checked via array_key_exists, not truthiness — so an
 * explicit `'required' => false` is honored).
 *
 * Per-column opt-out: set `'tcaValidation' => false` in the raw column config.
 *
 * For columns absent from the raw config (default-mode write resources that
 * declare no `columns` key at all), validators are NOT stub-injected here.
 * Consumers that need TCA-derived constraints for undeclared columns — namely
 * {@see \MaikSchneider\TcaApi\Validation\FieldValidator} and
 * {@see \MaikSchneider\TcaApi\OpenApi\OpenApiSchemasBuilder} — call the public
 * static helpers {@see deriveValidatorsForColumn()} and {@see isTcaColumnRequired()}
 * on-demand. TCA reads are in-memory array lookups so the cost is negligible.
 */
final class TcaValidatorDeriver
{
    public function deriveForConfig(string $table, array $rawConfig): array
    {
        if ($table === '') {
            return $rawConfig;
        }

        $tcaColumns = $GLOBALS['TCA'][$table]['columns'] ?? [];
        if (!\is_array($tcaColumns) || $tcaColumns === []) {
            return $rawConfig;
        }

        foreach ($rawConfig['columns'] ?? [] as $columnName => $columnRaw) {
            if (!\is_array($columnRaw)) {
                continue;
            }
            if (($columnRaw['tcaValidation'] ?? true) === false) {
                continue;
            }

            $tcaColumn = $tcaColumns[$columnName] ?? null;
            if (!\is_array($tcaColumn)) {
                continue;
            }

            $tcaConfig = $tcaColumn['config'] ?? [];
            $tcaType   = (string)($tcaConfig['type'] ?? '');

            // Derive validators (gap-fill only)
            $existingTypes = array_column($columnRaw['validators'] ?? [], 'type');
            foreach (self::deriveValidatorsForType($tcaType, $tcaConfig) as $derived) {
                if (!\in_array($derived['type'], $existingTypes, true)) {
                    $columnRaw['validators'][] = $derived;
                }
            }

            // Derive required flag from TCA (TYPO3 v13+ places `required` inside
            // the column's `config` array, sibling to `type`/`max`/etc.).
            // Only inject when the key is completely absent from the raw config.
            if (!\array_key_exists('required', $columnRaw) && ($tcaConfig['required'] ?? false)) {
                $columnRaw['required'] = true;
            }

            $rawConfig['columns'][$columnName] = $columnRaw;
        }

        return $rawConfig;
    }

    /**
     * Derive validator configs from TCA for a single column. Returns the same
     * shape the boot-time deriver injects into declared columns. Returns an
     * empty list when no constraints apply or the table/column is not in TCA.
     *
     * @return list<array<string, mixed>>
     */
    public static function deriveValidatorsForColumn(string $table, string $column): array
    {
        $tcaColumn = $GLOBALS['TCA'][$table]['columns'][$column] ?? null;
        if (!\is_array($tcaColumn)) {
            return [];
        }
        $tcaConfig = $tcaColumn['config'] ?? [];
        $tcaType   = (string)($tcaConfig['type'] ?? '');

        return self::deriveValidatorsForType($tcaType, $tcaConfig);
    }

    /**
     * Returns true when the TCA column declares the v13+ required flag.
     * The flag lives inside the column's `config` array, sibling to `type`/`max`.
     */
    public static function isTcaColumnRequired(string $table, string $column): bool
    {
        return ($GLOBALS['TCA'][$table]['columns'][$column]['config']['required'] ?? false) === true;
    }

    /**
     * @param array<string, mixed> $tcaConfig The inner TCA 'config' array for the column
     * @return list<array<string, mixed>>
     */
    private static function deriveValidatorsForType(string $tcaType, array $tcaConfig): array
    {
        $validators = [];

        switch ($tcaType) {
            case 'input':
            case 'text':
                if (isset($tcaConfig['max']) && (int)$tcaConfig['max'] > 0) {
                    $validators[] = ['type' => 'maxLength', 'max' => (int)$tcaConfig['max']];
                }
                break;

            case 'number':
                $range = $tcaConfig['range'] ?? [];
                if (\is_array($range)) {
                    $isDecimal = ($tcaConfig['format'] ?? '') === 'decimal';
                    if (\array_key_exists('lower', $range)) {
                        $bound = $isDecimal ? (float)$range['lower'] : (int)$range['lower'];
                        $validators[] = ['type' => 'minValue', 'min' => $bound];
                    }
                    if (\array_key_exists('upper', $range)) {
                        $bound = $isDecimal ? (float)$range['upper'] : (int)$range['upper'];
                        $validators[] = ['type' => 'maxValue', 'max' => $bound];
                    }
                }
                break;

            case 'group':
            case 'inline':
            case 'file':
            case 'category':
                if (isset($tcaConfig['maxitems'])) {
                    $validators[] = ['type' => 'maxItems', 'max' => (int)$tcaConfig['maxitems']];
                }
                if (isset($tcaConfig['minitems']) && (int)$tcaConfig['minitems'] > 0) {
                    $validators[] = ['type' => 'minItems', 'min' => (int)$tcaConfig['minitems']];
                }
                break;
        }

        return $validators;
    }
}
