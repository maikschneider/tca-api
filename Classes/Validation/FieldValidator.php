<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Validation;

use MaikSchneider\TcaApi\Configuration\ApiDefinition;

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
            // Default mode: only run declared validators — no required-check unless configured
            foreach ($config->columns as $column => $columnDef) {
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
            default     => null,
        };
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
