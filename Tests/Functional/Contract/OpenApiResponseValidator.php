<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Contract;

use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\Validator;

/**
 * Validates API responses against the OpenAPI spec that documents them.
 *
 * Object schemas are closed before validation (additionalProperties: false), so a
 * property the API sends but the spec does not list is a mismatch as well, not
 * only a property of the wrong type.
 *
 * Mismatches are returned as stable one-line signatures, e.g.
 * "GET /_api/articles 200 /hydra:member/* /article_url: type", for comparison with
 * the baseline of known mismatches.
 */
final class OpenApiResponseValidator
{
    private const SPEC_ID = 'https://tca-api.test/openapi.json';

    private readonly Validator $validator;

    /** @var array<string, array{template: string, pattern: string}> */
    private array $paths = [];

    private readonly object $spec;

    public function __construct(array $spec)
    {
        $decoded = json_decode(json_encode($spec, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
        if (!$decoded instanceof \stdClass) {
            throw new \InvalidArgumentException('The OpenAPI spec must be an object.', 1793260800);
        }
        self::closeObjectSchemas($decoded);
        $this->spec = $decoded;

        $this->validator = new Validator();
        $this->validator->setMaxErrors(50);
        $this->validator->setStopAtFirstError(false);
        $this->validator->resolver()?->registerRaw($decoded, self::SPEC_ID);

        foreach (array_keys((array)$decoded->paths) as $template) {
            $pattern = preg_replace('/\\\\\{[^\/]+?\\\\\}/', '[^/]+', preg_quote((string)$template, '#'));
            $this->paths[(string)$template] = ['template' => (string)$template, 'pattern' => '#' . $pattern . '$#'];
        }
    }

    /**
     * @return list<string> mismatch signatures; empty when the response matches or is not documented as JSON
     */
    public function validate(string $method, string $path, int $status, string $contentType, string $body): array
    {
        if (!preg_match('#^application/(ld\+)?json#', $contentType) || $body === '') {
            return [];
        }

        $template = $this->matchPath($path);
        $method   = strtolower($method);
        if ($template === null || !isset($this->spec->paths->{$template}->{$method})) {
            return [];
        }

        $prefix    = strtoupper($method) . ' ' . $template . ' ' . $status;
        $responses = $this->spec->paths->{$template}->{$method}->responses ?? new \stdClass();
        $code      = isset($responses->{(string)$status}) ? (string)$status : (isset($responses->default) ? 'default' : null);
        if ($code === null) {
            return [$prefix . ': status not documented'];
        }

        $content = $responses->{$code}->content ?? null;
        $media   = $content === null ? null : (isset($content->{'application/ld+json'}) ? 'application/ld+json' : (isset($content->{'application/json'}) ? 'application/json' : null));
        if ($media === null || !isset($content->{$media}->schema)) {
            return [$prefix . ': no JSON schema documented'];
        }

        $pointer = '#/paths/' . self::escape($template) . '/' . $method . '/responses/' . $code . '/content/' . self::escape($media) . '/schema';
        $error   = $this->validator->validate(json_decode($body, false, 512, JSON_THROW_ON_ERROR), self::SPEC_ID . $pointer)->error();
        if ($error === null) {
            return [];
        }

        $signatures = [];
        foreach (self::leafErrors($error) as $leaf) {
            $description = self::describe($leaf);
            if ($description !== null) {
                $signatures[] = $prefix . ' ' . $description;
            }
        }

        return array_values(array_unique($signatures));
    }

    private function matchPath(string $path): ?string
    {
        $match = null;
        foreach ($this->paths as $entry) {
            // The longest match wins, so "/_api/articles/{uid}" beats "/_api/articles".
            if (preg_match($entry['pattern'], $path) && ($match === null || \strlen($entry['template']) > \strlen($match))) {
                $match = $entry['template'];
            }
        }

        return $match;
    }

    /** @return list<ValidationError> */
    private static function leafErrors(ValidationError $error): array
    {
        if ($error->subErrors() === []) {
            return [$error];
        }

        if (\in_array($error->keyword(), ['oneOf', 'anyOf'], true)) {
            return self::closestBranchLeafErrors($error);
        }

        $leaves = [];
        foreach ($error->subErrors() as $subError) {
            array_push($leaves, ...self::leafErrors($subError));
        }

        return $leaves;
    }

    /**
     * When no branch of a oneOf/anyOf matches, every branch reports its errors. Only
     * the branch that came closest is relevant: one that failed on a nested value
     * rather than on the value's own type, and of those the one with the fewest errors.
     * E.g. for [FileObject, null], an object with an unlisted property fails FileObject,
     * not null.
     *
     * @return list<ValidationError>
     */
    private static function closestBranchLeafErrors(ValidationError $error): array
    {
        $ownPath  = $error->data()->fullPath();
        $branches = array_map(self::leafErrors(...), $error->subErrors());

        $nested = array_filter($branches, static function (array $leaves) use ($ownPath): bool {
            foreach ($leaves as $leaf) {
                if ($leaf->keyword() !== 'type' || $leaf->data()->fullPath() !== $ownPath) {
                    return true;
                }
            }
            return false;
        });
        $candidates = $nested !== [] ? $nested : $branches;
        usort($candidates, static fn (array $a, array $b): int => \count($a) <=> \count($b));

        return $candidates[0];
    }

    /** Null for an error that is only a side effect of another one. */
    private static function describe(ValidationError $error): ?string
    {
        $segments = array_map(
            static fn (int|string $segment): string => \is_int($segment) ? '*' : $segment,
            $error->data()->fullPath(),
        );
        $description = '/' . implode('/', $segments) . ': ' . $error->keyword();

        $args = $error->args();
        if ($error->keyword() === 'additionalProperties') {
            // Opis also reports declared properties when a sibling property failed validation.
            $schema   = $error->schema()->info()->data();
            $declared = $schema instanceof \stdClass && isset($schema->properties) ? array_keys(get_object_vars($schema->properties)) : [];
            $extra    = array_values(array_diff((array)($args['properties'] ?? []), $declared));
            if ($extra === []) {
                return null;
            }
            $description .= ' (' . implode(', ', $extra) . ')';
        }
        if ($error->keyword() === 'type' && isset($args['expected'], $args['type'])) {
            $description .= ' (' . implode('|', (array)$args['expected']) . ', got ' . $args['type'] . ')';
        }

        return $description;
    }

    /** JSON pointer segment inside a URI fragment: "/_api/articles/{uid}" → "~1_api~1articles~1%7Buid%7D". */
    private static function escape(string $pointerSegment): string
    {
        return rawurlencode(str_replace(['~', '/'], ['~0', '~1'], $pointerSegment));
    }

    private static function closeObjectSchemas(mixed $node): void
    {
        if (\is_array($node)) {
            foreach ($node as $child) {
                self::closeObjectSchemas($child);
            }
            return;
        }
        if (!$node instanceof \stdClass) {
            return;
        }

        if (($node->type ?? null) === 'object' && isset($node->properties) && !isset($node->additionalProperties)) {
            $node->additionalProperties = false;
        }
        foreach (get_object_vars($node) as $child) {
            self::closeObjectSchemas($child);
        }
    }
}
