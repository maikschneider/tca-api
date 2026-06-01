<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Configuration;

/**
 * Typed value object for a single column or virtual property configuration.
 *
 * The $groups property uses a sentinel: null means the 'groups' key was absent
 * in the raw config (default mode); [] means it was present but empty (still
 * triggers explicit mode). Use hasGroups() to distinguish these cases.
 */
final readonly class ColumnDefinition
{
    /**
     * @param array<string>|null $groups     null = key absent; [] = present but empty
     * @param array              $validators Raw validator config arrays
     */
    public function __construct(
        public readonly ?array $groups,
        public readonly ?string $type          = null,
        public readonly bool $required      = false,
        public readonly mixed $embed         = null,
        public readonly ?string $processor     = null,
        public readonly ?string $resourceName  = null,
        public readonly ?string $resourceType  = null,
        public readonly array $validators    = [],
        public readonly ?string $column        = null,
        public readonly mixed $callback      = null,
        public readonly ?UploadDefinition $upload = null,
        public readonly ?ImageDefinition $image = null,
    ) {
    }

    /**
     * Returns true when the 'groups' key was present in the raw column config.
     * An empty groups array still triggers explicit mode.
     */
    public function hasGroups(): bool
    {
        return $this->groups !== null;
    }

    /**
     * Returns true when the column is readable for the given operation.
     * When no operation is given, returns true if any read operation ('list' or 'show') is in groups.
     */
    public function isReadable(string $operation = ''): bool
    {
        $groups = $this->groups ?? [];

        if ($operation !== '') {
            return \in_array($operation, $groups, true);
        }

        return \in_array('list', $groups, true) || \in_array('show', $groups, true);
    }

    /**
     * Returns true when the column is writable for the given operation.
     * When no operation is given, returns true if any write operation ('create' or 'update') is in groups.
     */
    public function isWritable(string $operation = ''): bool
    {
        $groups = $this->groups ?? [];

        if ($operation !== '') {
            return \in_array($operation, $groups, true);
        }

        return \in_array('create', $groups, true) || \in_array('update', $groups, true);
    }

    /**
     * Resolves the embed depth from the embed config value.
     * null/false → 0; true → 1; ['depth' => N] → N.
     */
    public function embedDepth(): int
    {
        if (!$this->embed) {
            return 0;
        }
        if ($this->embed === true) {
            return 1;
        }
        if (\is_array($this->embed)) {
            return (int)($this->embed['depth'] ?? 1);
        }

        return 0;
    }

    /** Known operation names that may appear in the 'groups' array. */
    private const VALID_GROUPS = ['list', 'show', 'create', 'update', 'delete'];

    /** Allowed scalar types for the 'type' hint used in OpenAPI generation. */
    private const VALID_TYPES = ['string', 'integer', 'int', 'boolean', 'bool', 'number', 'float', 'double'];

    /** Recognised validator type identifiers. */
    private const VALID_VALIDATOR_TYPES = [
        'maxLength', 'minLength', 'regex',
        'minValue', 'maxValue',
        'minItems', 'maxItems',
    ];

    /**
     * Normalise a raw column config array, validate all fields, and return a typed ColumnDefinition.
     *
     * @throws \InvalidArgumentException when any field has an invalid shape or value.
     */
    public static function fromArray(array $raw): self
    {
        // ── groups ───────────────────────────────────────────────────────
        $groups = null;
        if (\array_key_exists('groups', $raw)) {
            $groupsRaw = $raw['groups'] ?? [];
            if (!\is_array($groupsRaw)) {
                throw new \InvalidArgumentException(
                    'Column config "groups" must be an array of operation names.',
                );
            }
            foreach ($groupsRaw as $group) {
                if (!\is_string($group) || !\in_array($group, self::VALID_GROUPS, true)) {
                    throw new \InvalidArgumentException(
                        sprintf(
                            'Column config "groups" contains invalid operation "%s". Allowed: %s',
                            \is_string($group) ? $group : \get_debug_type($group),
                            implode(', ', self::VALID_GROUPS),
                        ),
                    );
                }
            }
            $groups = $groupsRaw;
        }

        // ── type ─────────────────────────────────────────────────────────
        $type = $raw['type'] ?? null;
        if ($type !== null) {
            if (!\is_string($type)) {
                throw new \InvalidArgumentException('Column config "type" must be a string.');
            }
            if (!\in_array(strtolower($type), self::VALID_TYPES, true)) {
                throw new \InvalidArgumentException(
                    sprintf(
                        'Column config "type" has invalid value "%s". Allowed: %s',
                        $type,
                        implode(', ', self::VALID_TYPES),
                    ),
                );
            }
        }

        // ── required ─────────────────────────────────────────────────────
        if (isset($raw['required']) && !\is_bool($raw['required'])) {
            throw new \InvalidArgumentException('Column config "required" must be a boolean.');
        }

        // ── embed ────────────────────────────────────────────────────────
        $embed = $raw['embed'] ?? null;
        if ($embed !== null) {
            $validScalar = \is_bool($embed);
            $validArray  = \is_array($embed)
                && \array_key_exists('depth', $embed)
                && \is_int($embed['depth']);
            if (!$validScalar && !$validArray) {
                throw new \InvalidArgumentException(
                    'Column config "embed" must be true, false, or ["depth" => <int>].',
                );
            }
        }

        // ── processor ────────────────────────────────────────────────────
        $processor = $raw['processor'] ?? null;
        if ($processor !== null && !\is_string($processor)) {
            throw new \InvalidArgumentException('Column config "processor" must be a class-string.');
        }

        // ── callback ─────────────────────────────────────────────────────
        $callback = $raw['callback'] ?? null;
        if ($callback !== null) {
            if (
                !\is_array($callback)
                || \count($callback) !== 2
                || !isset($callback[0], $callback[1])
                || !\is_string($callback[0])
                || !\is_string($callback[1])
            ) {
                throw new \InvalidArgumentException(
                    'Column config "callback" must be a [class-string, method-string] tuple.',
                );
            }
        }

        // ── validators ───────────────────────────────────────────────────
        $validators = $raw['validators'] ?? [];
        if (!\is_array($validators)) {
            throw new \InvalidArgumentException('Column config "validators" must be an array.');
        }
        foreach ($validators as $index => $validator) {
            if (!\is_array($validator)) {
                throw new \InvalidArgumentException(
                    sprintf('Column config "validators[%s]" must be an array.', $index),
                );
            }
            $validatorType = $validator['type'] ?? null;
            if ($validatorType === null || !\in_array($validatorType, self::VALID_VALIDATOR_TYPES, true)) {
                throw new \InvalidArgumentException(
                    sprintf(
                        'Column config "validators[%s].type" is invalid ("%s"). Allowed: %s',
                        $index,
                        $validatorType ?? 'null',
                        implode(', ', self::VALID_VALIDATOR_TYPES),
                    ),
                );
            }

            // Validate regex pattern at config load time so broken patterns
            // are caught immediately, not silently at runtime.
            if ($validatorType === 'regex') {
                $pattern = $validator['pattern'] ?? null;
                if ($pattern === null || !\is_string($pattern) || $pattern === '') {
                    throw new \InvalidArgumentException(
                        sprintf(
                            'Column config "validators[%s].pattern" must be a non-empty string for regex validators.',
                            $index,
                        ),
                    );
                }
                if (@preg_match($pattern, '') === false) {
                    throw new \InvalidArgumentException(
                        sprintf(
                            'Column config "validators[%s].pattern" contains an invalid regex pattern "%s".',
                            $index,
                            $pattern,
                        ),
                    );
                }
            }
        }

        // ── column ───────────────────────────────────────────────────────
        $column = $raw['column'] ?? null;
        if ($column !== null && !\is_string($column)) {
            throw new \InvalidArgumentException('Column config "column" must be a string.');
        }

        // ── resourceName / resourceType ──────────────────────────────────
        $resourceName = $raw['resourceName'] ?? null;
        if ($resourceName !== null && !\is_string($resourceName)) {
            throw new \InvalidArgumentException('Column config "resourceName" must be a string.');
        }
        $resourceType = $raw['resourceType'] ?? null;
        if ($resourceType !== null && !\is_string($resourceType)) {
            throw new \InvalidArgumentException('Column config "resourceType" must be a string.');
        }

        // ── upload ───────────────────────────────────────────────────────
        $upload = null;
        if (\array_key_exists('upload', $raw)) {
            if (!\is_array($raw['upload'])) {
                throw new \InvalidArgumentException('Column config "upload" must be an array.');
            }
            $upload = UploadDefinition::fromArray($raw['upload']);
        }

        // ── image ─────────────────────────────────────────────────────────
        $image = null;
        if (\array_key_exists('image', $raw)) {
            if (!\is_array($raw['image'])) {
                throw new \InvalidArgumentException('Column config "image" must be an array.');
            }
            try {
                $image = ImageDefinition::fromArray($raw['image']);
            } catch (\InvalidArgumentException $e) {
                throw new \InvalidArgumentException(
                    sprintf('Column config "image": %s', $e->getMessage()),
                    0,
                    $e,
                );
            }
        }

        return new self(
            groups:       $groups,
            type:         $type,
            required:     (bool)($raw['required'] ?? false),
            embed:        $embed,
            processor:    $processor,
            resourceName: $resourceName,
            resourceType: $resourceType,
            validators:   $validators,
            column:       $column,
            callback:     $callback,
            upload:       $upload,
            image:        $image,
        );
    }
}
