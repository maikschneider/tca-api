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
     * null/false → 0; true → 1; ['depth' => N] → N; ['maxDepth' => N] → N (legacy).
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
            return max(0, (int)($this->embed['depth'] ?? $this->embed['maxDepth'] ?? 1));
        }

        return 0;
    }

    /**
     * Returns a plain array representation, for passing to legacy column processor interfaces.
     */
    public function toArray(): array
    {
        $result = [];

        if ($this->groups !== null) {
            $result['groups'] = $this->groups;
        }
        if ($this->type !== null) {
            $result['type'] = $this->type;
        }
        if ($this->required) {
            $result['required'] = $this->required;
        }
        if ($this->embed !== null) {
            $result['embed'] = $this->embed;
        }
        if ($this->processor !== null) {
            $result['processor'] = $this->processor;
        }
        if ($this->resourceName !== null) {
            $result['resourceName'] = $this->resourceName;
        }
        if ($this->resourceType !== null) {
            $result['resourceType'] = $this->resourceType;
        }
        if ($this->validators !== []) {
            $result['validators'] = $this->validators;
        }
        if ($this->column !== null) {
            $result['column'] = $this->column;
        }
        if ($this->callback !== null) {
            $result['callback'] = $this->callback;
        }

        return $result;
    }

    public static function fromArray(array $raw): self
    {
        return new self(
            groups:       \array_key_exists('groups', $raw) ? ($raw['groups'] ?? []) : null,
            type:         $raw['type'] ?? null,
            required:     (bool)($raw['required'] ?? false),
            embed:        $raw['embed'] ?? null,
            processor:    $raw['processor'] ?? null,
            resourceName: $raw['resourceName'] ?? null,
            resourceType: $raw['resourceType'] ?? null,
            validators:   $raw['validators'] ?? [],
            column:       $raw['column'] ?? null,
            callback:     $raw['callback'] ?? null,
        );
    }
}
