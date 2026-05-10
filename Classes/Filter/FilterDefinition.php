<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Filter;

/**
 * Typed, immutable configuration object for a single filter column.
 *
 * Created by ApiDefinition::fromArray() from the raw 'filters' config array.
 * Filters implementing FilterPreResolvableInterface receive this object in
 * preResolve() to derive expensive config (e.g. TCA lookups) once at load time.
 *
 * Well-known meta-keys ('private', 'default') are lifted into dedicated properties
 * so consumers can read them without knowing the raw options shape.
 */
final readonly class FilterDefinition
{
    /**
     * @param string               $filterClass Fully-qualified class name of the filter.
     * @param string               $table       Resource table name (known at load time).
     * @param string               $column      Column name this filter is applied to.
     * @param array<string, mixed> $options     Filter-specific options from the resource config,
     *                                          with 'private' and 'default' already stripped.
     * @param bool                 $isPrivate   True when the filter value comes only from $default,
     *                                          ignoring any request parameter.
     * @param mixed                $default     Default value used when the request provides none,
     *                                          or the only value when $isPrivate is true.
     */
    public function __construct(
        public readonly string $filterClass,
        public readonly string $table,
        public readonly string $column,
        public readonly array $options = [],
        public readonly bool $isPrivate = false,
        public readonly mixed $default = null,
    ) {
    }

    /**
     * Returns a filter-specific option, or $default when not set.
     */
    public function option(string $key, mixed $default = null): mixed
    {
        return $this->options[$key] ?? $default;
    }

    /**
     * Returns a copy of this definition with additional options merged in.
     * Used by FilterPreResolvableInterface implementations to store derived config.
     *
     * @param array<string, mixed> $extra
     */
    public function withOptions(array $extra): self
    {
        return new self(
            filterClass: $this->filterClass,
            table:       $this->table,
            column:      $this->column,
            options:     array_merge($this->options, $extra),
            isPrivate:   $this->isPrivate,
            default:     $this->default,
        );
    }

    /**
     * Normalises a raw filter entry from a TcaApi config array.
     *
     * Accepted shapes:
     *   ExactFilter::class                         — simple class-string
     *   [MmFilter::class, ['mm_table' => 'sys_…']] — class-string + options array
     *
     * The meta-keys 'private' and 'default' are lifted out of options
     * into the dedicated $isPrivate / $default properties.
     *
     * @param mixed                                   $raw       The value from $rawConfig['filters'][$column].
     * @param array<string, FilterPreResolvableInterface> $filterMap DI-managed filter instances keyed by FQCN.
     *                                                              When the filter class is present in this map,
     *                                                              preResolve() is called before returning so that
     *                                                              expensive config (e.g. TCA lookups) is derived
     *                                                              once at load time rather than per request.
     * @throws \InvalidArgumentException for any other shape.
     */
    public static function fromRaw(string $table, string $column, mixed $raw, array $filterMap = []): self
    {
        if (\is_string($raw)) {
            $def = new self(
                filterClass: $raw,
                table:       $table,
                column:      $column,
            );

            return ($filterMap[$raw] ?? null)?->preResolve($def) ?? $def;
        }

        if (\is_array($raw) && \is_string($raw[0] ?? null)) {
            if (isset($raw[1]) && !\is_array($raw[1])) {
                throw new \InvalidArgumentException(
                    sprintf('filter "%s" options (second element) must be an array.', $column),
                );
            }

            $options   = $raw[1] ?? [];
            $isPrivate = (bool)($options['private'] ?? false);
            $default   = $options['default'] ?? null;
            $cleanOpts = array_diff_key($options, array_flip(['private', 'default']));

            $def = new self(
                filterClass: $raw[0],
                table:       $table,
                column:      $column,
                options:     $cleanOpts,
                isPrivate:   $isPrivate,
                default:     $default,
            );

            return ($filterMap[$raw[0]] ?? null)?->preResolve($def) ?? $def;
        }

        throw new \InvalidArgumentException(
            sprintf(
                'filter "%s" must be a class-string or [class-string, options-array].',
                $column,
            ),
        );
    }
}
