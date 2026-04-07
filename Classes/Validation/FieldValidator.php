<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Validation;

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

        foreach ($config['columns'] as $column => $columnConfig) {
            if (!($columnConfig['writable'] ?? false)) {
                continue;
            }

            $provided = \array_key_exists($column, $body);

            if ($partial && !$provided) {
                continue; // PATCH: ignore fields not present in the request body
            }

            // required check
            if ($columnConfig['required'] ?? false) {
                if (!$provided || $body[$column] === '' || $body[$column] === null) {
                    $violations[] = [
                        'propertyPath' => $column,
                        'message'      => "Field '$column' is required.",
                        'code'         => 'REQUIRED',
                    ];
                    continue; // no point running further validators on a missing value
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
            default     => null,
        };
    }

    private function validateMaxLength(string $column, mixed $value, int $max): ?array
    {
        if (mb_strlen((string)$value) > $max) {
            return [
                'propertyPath' => $column,
                'message'      => "Field '$column' must not exceed $max characters.",
                'code'         => 'MAX_LENGTH',
            ];
        }

        return null;
    }
}
