<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Validation;

use MaikSchneider\TcaApi\Configuration\ApiDefinition;
use MaikSchneider\TcaApi\Loader\TcaValidatorDeriver;
use MaikSchneider\TcaApi\Utility\TcaColumnDiscovery;

final class FieldValidator
{
    /**
     * Validate $body against the column config.
     *
     * @param bool $partial When true (PATCH), skip required-check for absent fields
     *                      but still validate any field that IS provided.
     *
     * @return list<array{propertyPath: string, message: string, code: string}>
     */
    public function validate(array $body, ApiDefinition $config, bool $partial = false): array
    {
        $violations = [];

        if (!$config->isExplicitMode) {
            // Default mode: run declared validators (already gap-filled at boot)…
            $seen = [];
            foreach ($config->columns as $column => $columnDef) {
                $seen[$column] = true;
                $provided = \array_key_exists($column, $body);
                if ($partial && !$provided) {
                    continue;
                }
                if ($provided) {
                    foreach ($columnDef->validators as $validatorConfig) {
                        $violation = $this->applyValidator($validatorConfig, $column, $body[$column]);
                        if ($violation !== null) {
                            $violations[] = $violation;
                        }
                    }
                }
            }

            // …and on-demand TCA-derived validators for exposable columns not
            // present in $config->columns. Symmetric with ColumnFilterTrait,
            // which iterates the same set when filtering writable input.
            foreach (TcaColumnDiscovery::getExposableColumnNames($config->table) as $column) {
                if (isset($seen[$column])) {
                    continue;
                }
                $provided = \array_key_exists($column, $body);
                if (!$provided) {
                    continue; // no value supplied → nothing to validate
                }
                foreach (TcaValidatorDeriver::deriveValidatorsForColumn($config->table, $column) as $validatorConfig) {
                    $violation = $this->applyValidator($validatorConfig, $column, $body[$column]);
                    if ($violation !== null) {
                        $violations[] = $violation;
                    }
                }
            }

            return $violations;
        }

        // Explicit mode
        foreach ($config->columns as $column => $columnDef) {
            if (!$columnDef->isWritable()) {
                continue;
            }

            $provided = \array_key_exists($column, $body);

            if ($partial && !$provided) {
                continue; // PATCH: ignore fields not present in the request body
            }

            // required check
            if ($columnDef->required) {
                if (!$provided || $body[$column] === '' || $body[$column] === null) {
                    $violations[] = $this->buildViolation($column, "Field '$column' is required.", 'REQUIRED');
                    continue;
                }
            }

            // declared validators
            if ($provided) {
                foreach ($columnDef->validators as $validatorConfig) {
                    $violation = $this->applyValidator($validatorConfig, $column, $body[$column]);
                    if ($violation !== null) {
                        $violations[] = $violation;
                    }
                }
            }
        }

        return $violations;
    }

    /**
     * @return array{propertyPath: string, message: string, code: string}|null
     */
    private function applyValidator(array $validatorConfig, string $column, mixed $value): ?array
    {
        return match ($validatorConfig['type'] ?? '') {
            'maxLength' => $this->validateMaxLength($column, $value, (int)$validatorConfig['max']),
            'minLength' => $this->validateMinLength($column, $value, (int)$validatorConfig['min']),
            'regex'     => $this->validateRegex($column, $value, (string)$validatorConfig['pattern']),
            'minValue'  => $this->validateMinValue($column, $value, $validatorConfig['min']),
            'maxValue'  => $this->validateMaxValue($column, $value, $validatorConfig['max']),
            'minItems'  => $this->validateMinItems($column, $value, (int)$validatorConfig['min']),
            'maxItems'  => $this->validateMaxItems($column, $value, (int)$validatorConfig['max']),
            default     => null,
        };
    }

    /**
     * @return array{propertyPath: string, message: string, code: string}|null
     */
    private function validateMinValue(string $column, mixed $value, int|float $min): ?array
    {
        if (!is_numeric($value)) {
            return null;
        }
        if ((float)$value < (float)$min) {
            return $this->buildViolation($column, "Field '$column' must be at least $min.", 'MIN_VALUE');
        }
        return null;
    }

    /**
     * @return array{propertyPath: string, message: string, code: string}|null
     */
    private function validateMaxValue(string $column, mixed $value, int|float $max): ?array
    {
        if (!is_numeric($value)) {
            return null;
        }
        if ((float)$value > (float)$max) {
            return $this->buildViolation($column, "Field '$column' must not exceed $max.", 'MAX_VALUE');
        }
        return null;
    }

    /**
     * @return array{propertyPath: string, message: string, code: string}|null
     */
    private function validateMinItems(string $column, mixed $value, int $min): ?array
    {
        if (!is_array($value)) {
            return null;
        }
        if (count($value) < $min) {
            return $this->buildViolation($column, "Field '$column' must have at least $min item(s).", 'MIN_ITEMS');
        }
        return null;
    }

    /**
     * @return array{propertyPath: string, message: string, code: string}|null
     */
    private function validateMaxItems(string $column, mixed $value, int $max): ?array
    {
        if (!is_array($value)) {
            return null;
        }
        if (count($value) > $max) {
            return $this->buildViolation($column, "Field '$column' must not have more than $max item(s).", 'MAX_ITEMS');
        }
        return null;
    }

    /**
     * @return array{propertyPath: string, message: string, code: string}|null
     */
    private function validateMaxLength(string $column, mixed $value, int $max): ?array
    {
        if (mb_strlen((string)$value) > $max) {
            return $this->buildViolation($column, "Field '$column' must not exceed $max characters.", 'MAX_LENGTH');
        }

        return null;
    }

    /**
     * @return array{propertyPath: string, message: string, code: string}|null
     */
    private function validateMinLength(string $column, mixed $value, int $min): ?array
    {
        if (mb_strlen((string)$value) < $min) {
            return $this->buildViolation($column, "Field '$column' must be at least $min characters.", 'MIN_LENGTH');
        }

        return null;
    }

    /**
     * @return array{propertyPath: string, message: string, code: string}|null
     */
    private function validateRegex(string $column, mixed $value, string $pattern): ?array
    {
        $result = @preg_match($pattern, (string)$value);
        if ($result === false) {
            return $this->buildViolation($column, "Field '$column' has an invalid validation pattern.", 'REGEX_ERROR');
        }
        if ($result !== 1) {
            return $this->buildViolation($column, "Field '$column' does not match the required pattern.", 'REGEX');
        }

        return null;
    }

    /**
     * @return array{propertyPath: string, message: string, code: string}
     */
    private function buildViolation(string $column, string $message, string $code): array
    {
        return ['propertyPath' => $column, 'message' => $message, 'code' => $code];
    }
}
