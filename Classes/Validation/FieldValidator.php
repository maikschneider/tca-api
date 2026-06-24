<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Validation;

use MaikSchneider\TcaApi\Configuration\ApiDefinition;
use MaikSchneider\TcaApi\Loader\TcaValidatorDeriver;
use MaikSchneider\TcaApi\Utility\TcaColumnDiscovery;
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;

final class FieldValidator
{
    /** @var array<class-string<ValidatorInterface>, ValidatorInterface>|null */
    private ?array $validatorMap = null;

    /**
     * @param iterable<ValidatorInterface> $customValidators Auto-discovered via the 'tca_api.validator' tag.
     */
    public function __construct(
        #[TaggedIterator('tca_api.validator')]
        private readonly iterable $customValidators = [],
    ) {
    }

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
            // Default mode mirrors explicit mode's required/validator semantics.
            // Pass 1: declared columns (validators + required already gap-filled at boot).
            $seen = [];
            foreach ($config->columns as $column => $columnDef) {
                $seen[$column] = true;
                $provided = \array_key_exists($column, $body);
                if ($partial && !$provided) {
                    continue;
                }
                if ($columnDef->required && (!$provided || $body[$column] === '' || $body[$column] === null)) {
                    $violations[] = $this->buildViolation($column, "Field '$column' is required.", 'REQUIRED');
                    continue;
                }
                if ($provided) {
                    foreach ($columnDef->validators as $validatorConfig) {
                        array_push($violations, ...$this->applyValidator($validatorConfig, $column, $body[$column], $config, $body, $partial));
                    }
                }
            }

            // Pass 2: exposable TCA columns not in $config->columns — derive
            // validators and the required flag on demand. Symmetric with
            // ColumnFilterTrait, which iterates the same set on writes.
            foreach (TcaColumnDiscovery::getExposableColumnNames($config->table) as $column) {
                if (isset($seen[$column])) {
                    continue;
                }
                $provided = \array_key_exists($column, $body);
                if ($partial && !$provided) {
                    continue;
                }
                $required = TcaValidatorDeriver::isTcaColumnRequired($config->table, $column);
                if ($required && (!$provided || $body[$column] === '' || $body[$column] === null)) {
                    $violations[] = $this->buildViolation($column, "Field '$column' is required.", 'REQUIRED');
                    continue;
                }
                if (!$provided) {
                    continue;
                }
                foreach (TcaValidatorDeriver::deriveValidatorsForColumn($config->table, $column) as $validatorConfig) {
                    array_push($violations, ...$this->applyValidator($validatorConfig, $column, $body[$column], $config, $body, $partial));
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
                    array_push($violations, ...$this->applyValidator($validatorConfig, $column, $body[$column], $config, $body, $partial));
                }
            }
        }

        return $violations;
    }

    /**
     * Apply one validator config to a column value.
     *
     * Built-in types resolve to the shipped checks below; a class-string type
     * dispatches to the matching custom ValidatorInterface. Returns zero or more
     * violations so a single custom validator can report multiple failures.
     *
     * @return list<array{propertyPath: string, message: string, code: string}>
     */
    private function applyValidator(
        array $validatorConfig,
        string $column,
        mixed $value,
        ApiDefinition $config,
        array $body,
        bool $partial,
    ): array {
        $type = $validatorConfig['type'] ?? '';

        // Custom validator: referenced by class-string, resolved from the DI tag.
        if (\is_string($type) && $type !== '' && class_exists($type)) {
            return $this->applyCustomValidator($type, $validatorConfig, $column, $value, $config, $body, $partial);
        }

        $violation = match ($type) {
            'maxLength' => $this->validateMaxLength($column, $value, (int)$validatorConfig['max']),
            'minLength' => $this->validateMinLength($column, $value, (int)$validatorConfig['min']),
            'regex'     => $this->validateRegex($column, $value, (string)$validatorConfig['pattern']),
            'minValue'  => $this->validateMinValue($column, $value, $validatorConfig['min']),
            'maxValue'  => $this->validateMaxValue($column, $value, $validatorConfig['max']),
            'minItems'  => $this->validateMinItems($column, $value, (int)$validatorConfig['min']),
            'maxItems'  => $this->validateMaxItems($column, $value, (int)$validatorConfig['max']),
            default     => null,
        };

        return $violation !== null ? [$violation] : [];
    }

    /**
     * Resolve a custom validator by class-string and map its Violations to the
     * internal violation-array shape (defaulting propertyPath to the column).
     *
     * @return list<array{propertyPath: string, message: string, code: string}>
     */
    private function applyCustomValidator(
        string $type,
        array $validatorConfig,
        string $column,
        mixed $value,
        ApiDefinition $config,
        array $body,
        bool $partial,
    ): array {
        $context = new ValidationContext(
            value:          $value,
            table:          $config->table,
            column:         $column,
            options:        (array)($validatorConfig['options'] ?? []),
            body:           $body,
            partial:        $partial,
            resourceConfig: $config,
        );

        $violations = [];
        foreach ($this->resolveValidator($type)->validate($context) as $violation) {
            $violations[] = $this->buildViolation($violation->propertyPath ?? $column, $violation->message, $violation->code);
        }

        return $violations;
    }

    private function resolveValidator(string $fqcn): ValidatorInterface
    {
        if ($this->validatorMap === null) {
            $this->validatorMap = [];
            foreach ($this->customValidators as $validator) {
                $this->validatorMap[$validator::class] = $validator;
            }
        }

        return $this->validatorMap[$fqcn] ?? throw new \InvalidArgumentException(
            sprintf(
                'No validator registered for class "%s". Ensure it implements %s and is a registered service (autoconfigure applies the "tca_api.validator" tag).',
                $fqcn,
                ValidatorInterface::class,
            ),
        );
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
