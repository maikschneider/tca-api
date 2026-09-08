<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Filter;

/**
 * Normalised filter value: the comparison filters accept either a single scalar
 * (`?filters[color_id]=1`) or a list (`?filters[color_id][]=1&filters[color_id][]=2`),
 * and compare with `=` or `IN` accordingly.
 *
 * The `negate` option flips every filter that builds on this to its negative form
 * (`!=` / `NOT IN` / `NOT LIKE`). Negation is a server-side decision — it is read
 * from the resource config, never from the request.
 *
 * Options read here (valid on every filter that uses a ValueSet):
 *
 *   negate     bool    compare with the negated operator (default false)
 *   separator  string  split a string value on this separator into a list (default: off)
 *   maxValues  int     upper bound on list length (default 100)
 */
final readonly class ValueSet
{
    public const DEFAULT_MAX_VALUES = 100;

    /**
     * @param list<scalar> $values
     */
    private function __construct(
        public array $values,
        public bool $negate,
    ) {
    }

    public static function fromContext(FilterContext $context): self
    {
        return new self(
            self::normalise($context),
            (bool)$context->option('negate', false),
        );
    }

    public function isEmpty(): bool
    {
        return $this->values === [];
    }

    public function isMulti(): bool
    {
        return \count($this->values) > 1;
    }

    /**
     * The single value to compare against. Only meaningful when the set is not empty;
     * for a multi-value set this is the first entry.
     */
    public function first(): string|int|float|bool
    {
        return $this->values[0] ?? '';
    }

    /**
     * @return list<scalar>
     */
    private static function normalise(FilterContext $context): array
    {
        $raw = $context->value;

        if (\is_array($raw)) {
            $values = [];
            foreach ($raw as $entry) {
                if ($entry === null) {
                    continue;
                }
                if (!\is_scalar($entry)) {
                    throw new FilterValueException(
                        sprintf('Filter "%s" does not accept nested values.', $context->column),
                    );
                }
                $values[] = $entry;
            }
        } elseif (\is_string($raw) && \is_string($context->option('separator')) && $context->option('separator') !== '') {
            $values = array_filter(
                array_map('trim', explode((string)$context->option('separator'), $raw)),
                static fn (string $entry): bool => $entry !== '',
            );
        } elseif (\is_scalar($raw)) {
            $values = [$raw];
        } else {
            $values = [];
        }

        $values = array_values(array_unique($values, SORT_REGULAR));

        $max = (int)$context->option('maxValues', self::DEFAULT_MAX_VALUES);
        if ($max > 0 && \count($values) > $max) {
            throw new FilterValueException(sprintf(
                'Filter "%s" accepts at most %d values, %d given.',
                $context->column,
                $max,
                \count($values),
            ));
        }

        return $values;
    }
}
