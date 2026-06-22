<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Frontend;

/**
 * Transforms canonical Hydra JSON-LD serializer output into clean PHP arrays for
 * server-side rendering (Fluid templates etc.).
 *
 * The JSON-LD envelope keys (`@id`, `@type`, `@context`, and any `hydra:*`
 * metadata) are stripped recursively so templates address plain properties —
 * `{article.title}`, `{article.color.name}`, `{article.image.publicUrl}`.
 *
 * `uid` is a real (non-`@`) column and therefore survives: a shallow,
 * non-embedded relation stub (`['@id' => …, '@type' => …, 'uid' => 5]`) collapses
 * to `['uid' => 5]`, preserving the reference. Embedded relations recurse as
 * nested clean arrays; hasMany relations (sequential lists) are mapped element by
 * element. Scalars, image arrays, and virtual properties pass through unchanged.
 */
final class FluidArrayNormalizer
{
    /**
     * @param list<array<string, mixed>> $records
     * @return list<array<string, mixed>>
     */
    public function normalizeCollection(array $records): array
    {
        return array_map(fn (array $record): array => $this->normalize($record), $records);
    }

    /**
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    public function normalize(array $record): array
    {
        /** @var array<string, mixed> $cleaned */
        $cleaned = $this->clean($record);

        return $cleaned;
    }

    /**
     * @param array<array-key, mixed> $node
     * @return array<array-key, mixed>
     */
    private function clean(array $node): array
    {
        if (array_is_list($node)) {
            return array_map(
                fn (mixed $value): mixed => \is_array($value) ? $this->clean($value) : $value,
                $node,
            );
        }

        $result = [];
        foreach ($node as $key => $value) {
            if (\is_string($key) && (str_starts_with($key, '@') || str_starts_with($key, 'hydra:'))) {
                continue;
            }
            $result[$key] = \is_array($value) ? $this->clean($value) : $value;
        }

        return $result;
    }
}
