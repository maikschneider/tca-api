<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Validation;

use MaikSchneider\TcaApi\Utility\TcaColumnDiscovery;

class FieldValidator
{
    /**
     * Validate $body against the column config.
     *
     * @param bool $partial When true (PATCH), skip required-check for absent fields
     *                      but still validate any field that IS provided.
     *
     * @return list<array{propertyPath: string, message: string, code: string}>
     */
    public function validate(array $body, array $config, bool $partial = false): array
    {
        $violations = [];

        if (!TcaColumnDiscovery::isExplicitMode($config)) {
            // Default mode: only run declared validators — no required-check unless configured
            foreach ($config['columns'] ?? [] as $column => $columnConfig) {
                $provided = \array_key_exists($column, $body);
                if ($partial && !$provided) {
                    continue;
                }
                if ($provided) {
                    foreach ($columnConfig['validators'] ?? [] as $validatorConfig) {
                        $violation = $this->applyValidator($validatorConfig, $column, $body[$column]);
                        if ($violation !== null) {
                            $violations[] = $violation;
                        }
                    }
                }
            }
            return $violations;
        }

        // Explicit mode
        foreach ($config['columns'] as $column => $columnConfig) {
            if (!TcaColumnDiscovery::isColumnWritable($columnConfig)) {
                continue;
            }

            $provided = \array_key_exists($column, $body);

            if ($partial && !$provided) {
                continue; // PATCH: ignore fields not present in the request body
            }

            // required check
            if ($columnConfig['required'] ?? false) {
                if (!$provided || $body[$column] === '' || $body[$column] === null) {
                    $violations[] = $this->buildViolation($column, "Field '$column' is required.", 'REQUIRED');
                    continue;
                }
            }

            // declared validators
            if ($provided) {
                foreach ($columnConfig['validators'] ?? [] as $validatorConfig) {
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
            default     => null,
        };
    }

    private function validateMaxLength(string $column, mixed $value, int $max): ?array
    {
        if (mb_strlen((string)$value) > $max) {
            return $this->buildViolation($column, "Field '$column' must not exceed $max characters.", 'MAX_LENGTH');
        }

        return null;
    }

    private function validateMinLength(string $column, mixed $value, int $min): ?array
    {
        if (mb_strlen((string)$value) < $min) {
            return $this->buildViolation($column, "Field '$column' must be at least $min characters.", 'MIN_LENGTH');
        }

        return null;
    }

    private function validateRegex(string $column, mixed $value, string $pattern): ?array
    {
        if (preg_match($pattern, (string)$value) !== 1) {
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
