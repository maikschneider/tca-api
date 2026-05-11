<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Filter;

/**
 * Immutable configuration object for a single filter column.
 *
 * Created by fromRaw() during ApiDefinition::fromArray(). The meta-keys
 * 'private' and 'default' are lifted into dedicated properties; everything
 * else stays in $options.
 */
final readonly class FilterDefinition
{
    /**
     * @param string               $filterClass FQCN of the filter implementation.
     * @param array<string, mixed> $options     Filter-specific options (without 'private'/'default').
     * @param bool                 $isPrivate   When true the filter value comes only from $default.
     * @param mixed                $default     Default value when the request provides none.
     */
    public function __construct(
        public string $filterClass,
        public array $options = [],
        public bool $isPrivate = false,
        public mixed $default = null,
    ) {}

    /**
     * Convenience accessor for a single option key.
     */
    public function option(string $key, mixed $default = null): mixed
    {
        return $this->options[$key] ?? $default;
    }

    /**
     * Return a copy with additional/overridden options.
     *
     * @param array<string, mixed> $extra
     */
    public function withOptions(array $extra): self
    {
        return new self(
            filterClass: $this->filterClass,
            options:     array_merge($this->options, $extra),
            isPrivate:   $this->isPrivate,
            default:     $this->default,
        );
    }

    /**
     * Build a FilterDefinition from a raw config entry.
     *
     * Accepted shapes:
     *   ExactFilter::class                         — class-string
     *   [MmFilter::class, ['mm_table' => 'sys_…']] — class-string + options
     *
     * @param string $table  Resource table (forwarded to preResolve only).
     * @param string $column Column name (forwarded to preResolve only).
     * @param array<string, FilterPreResolvableInterface> $filterMap
     *
     * @throws \InvalidArgumentException
     */
    public static function fromRaw(string $table, string $column, mixed $raw, array $filterMap = []): self
    {
        if (\is_string($raw)) {
            $def = new self(filterClass: $raw);

            return self::preResolveIfPossible($def, $table, $column, $filterMap);
        }

        if (\is_array($raw) && \is_string($raw[0] ?? null)) {
            if (isset($raw[1]) && !\is_array($raw[1])) {
                throw new \InvalidArgumentException(
                    sprintf('filter "%s" options (second element) must be an array.', $column),
                );
            }

            $options = $raw[1] ?? [];
            $def = new self(
                filterClass: $raw[0],
                options:     array_diff_key($options, ['private' => 1, 'default' => 1]),
                isPrivate:   (bool)($options['private'] ?? false),
                default:     $options['default'] ?? null,
            );

            return self::preResolveIfPossible($def, $table, $column, $filterMap);
        }

        throw new \InvalidArgumentException(
            sprintf('filter "%s" must be a class-string or [class-string, options-array].', $column),
        );
    }

    /**
     * @param array<string, FilterPreResolvableInterface> $filterMap
     */
    private static function preResolveIfPossible(self $def, string $table, string $column, array $filterMap): self
    {
        $handler = $filterMap[$def->filterClass] ?? null;

        return $handler !== null ? $handler->preResolve($def, $table, $column) : $def;
    }
}
