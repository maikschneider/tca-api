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
            foreach ($this->deriveValidators($tcaType, $tcaConfig) as $derived) {
                if (!\in_array($derived['type'], $existingTypes, true)) {
                    $columnRaw['validators'][] = $derived;
                }
            }

            // Derive required flag from TCA column-level key (TYPO3 v13+).
            // Only inject when the key is completely absent from the raw config.
            if (!\array_key_exists('required', $columnRaw) && ($tcaColumn['required'] ?? false)) {
                $columnRaw['required'] = true;
            }

            $rawConfig['columns'][$columnName] = $columnRaw;
        }

        return $rawConfig;
    }

    /**
     * @param array<string, mixed> $tcaConfig The inner TCA 'config' array for the column
     * @return list<array<string, mixed>>
     */
    private function deriveValidators(string $tcaType, array $tcaConfig): array
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
                    if (\array_key_exists('lower', $range)) {
                        $validators[] = ['type' => 'minValue', 'min' => (int)$range['lower']];
                    }
                    if (\array_key_exists('upper', $range)) {
                        $validators[] = ['type' => 'maxValue', 'max' => (int)$range['upper']];
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
