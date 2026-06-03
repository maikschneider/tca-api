<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Configuration;

/**
 * Typed configuration for the RouteEnhancerProcessor.
 *
 * Drives a single URL generation per record. The processor turns this
 * definition into a call against the TYPO3 site router, which transparently
 * applies any routeEnhancer configured for the target page (e.g. extbase
 * News detail → /news/{slug}).
 *
 * Usage in Configuration/TcaApi/MyResource.php:
 *
 *   'virtualProperties' => [
 *       'url' => [
 *           'processor' => RouteEnhancerProcessor::class,
 *           'route' => [
 *               'pid'        => '{$tca_api.news.detailPid}',
 *               'extension'  => 'News',
 *               'plugin'     => 'Pi1',
 *               'controller' => 'News',
 *               'action'     => 'detail',
 *               'arguments'  => ['news' => '{uid}'],
 *           ],
 *       ],
 *   ],
 *
 * Placeholder grammar — recognised in pid, arguments[*] and parameters[*]:
 *   {column_name}        → value from the raw DB row
 *   {$site.setting.key}  → value from the current SiteSettings
 *
 * Anything else is treated as a literal.
 */
final readonly class RouteDefinition
{
    /**
     * @param int|string         $pid        Target page id. Literal int, or a placeholder
     *                                       string ("{detail_pid}" / "{$tca_api.news.detailPid}").
     * @param string|null        $extension  Extbase extension key (UpperCamelCase, e.g. "News").
     *                                       Combined with $plugin to build the tx_ext_plugin namespace.
     * @param string|null        $plugin     Extbase plugin name (e.g. "Pi1").
     * @param string|null        $controller Extbase controller (e.g. "News").
     * @param string|null        $action     Extbase action (e.g. "detail").
     * @param array<string, mixed> $arguments  Extbase plugin arguments. Values may contain placeholders.
     *                                         Wrapped under "tx_<ext>_<plugin>" when $extension+$plugin set;
     *                                         otherwise ignored.
     * @param array<string, mixed> $parameters Plain top-level query parameters. Values may contain placeholders.
     * @param bool               $absolute   Force an absolute URL. Defaults to true (API consumers usually
     *                                       cross domains).
     * @param string|null        $fragment   Optional URL fragment (without the leading #).
     */
    public function __construct(
        public readonly int|string $pid,
        public readonly ?string $extension = null,
        public readonly ?string $plugin = null,
        public readonly ?string $controller = null,
        public readonly ?string $action = null,
        public readonly array $arguments = [],
        public readonly array $parameters = [],
        public readonly bool $absolute = true,
        public readonly ?string $fragment = null,
    ) {
    }

    /**
     * True when extbase routing info is present and the arguments should be
     * wrapped under the tx_<ext>_<plugin> namespace.
     */
    public function isExtbase(): bool
    {
        return $this->extension !== null && $this->plugin !== null;
    }

    /**
     * Build the "tx_<extension>_<plugin>" query namespace key.
     * Extbase lower-cases the extension key when composing this prefix.
     */
    public function extbaseNamespace(): string
    {
        return sprintf('tx_%s_%s', strtolower((string)$this->extension), strtolower((string)$this->plugin));
    }

    /**
     * Normalise a raw 'route' config array and return a typed RouteDefinition.
     *
     * @throws \InvalidArgumentException on invalid values.
     */
    public static function fromArray(array $raw): self
    {
        // ── pid (required) ───────────────────────────────────────────────
        if (!\array_key_exists('pid', $raw)) {
            throw new \InvalidArgumentException('Route config "pid" is required.');
        }
        $pid = $raw['pid'];
        if (!\is_int($pid) && !\is_string($pid)) {
            throw new \InvalidArgumentException(
                'Route config "pid" must be an int (literal page id) or a string placeholder.',
            );
        }
        if (\is_string($pid) && $pid === '') {
            throw new \InvalidArgumentException('Route config "pid" must not be an empty string.');
        }
        if (\is_int($pid) && $pid < 1) {
            throw new \InvalidArgumentException('Route config "pid" must be a positive integer.');
        }

        // ── extension / plugin / controller / action ──────────────────────
        foreach (['extension', 'plugin', 'controller', 'action'] as $key) {
            if (isset($raw[$key]) && (!\is_string($raw[$key]) || $raw[$key] === '')) {
                throw new \InvalidArgumentException(
                    sprintf('Route config "%s" must be a non-empty string.', $key),
                );
            }
        }

        // Extbase routing requires extension AND plugin together.
        $hasExtension = isset($raw['extension']);
        $hasPlugin    = isset($raw['plugin']);
        if ($hasExtension !== $hasPlugin) {
            throw new \InvalidArgumentException(
                'Route config "extension" and "plugin" must be declared together (or both omitted).',
            );
        }

        // ── arguments / parameters ────────────────────────────────────────
        foreach (['arguments', 'parameters'] as $key) {
            if (isset($raw[$key]) && !\is_array($raw[$key])) {
                throw new \InvalidArgumentException(
                    sprintf('Route config "%s" must be an array.', $key),
                );
            }
        }

        // Arguments only meaningful with extbase routing.
        if (!empty($raw['arguments']) && !$hasExtension) {
            throw new \InvalidArgumentException(
                'Route config "arguments" requires "extension" + "plugin" to be set. '
                . 'Use "parameters" for plain top-level query parameters.',
            );
        }

        // ── absolute / fragment ───────────────────────────────────────────
        if (isset($raw['absolute']) && !\is_bool($raw['absolute'])) {
            throw new \InvalidArgumentException('Route config "absolute" must be a boolean.');
        }
        if (isset($raw['fragment']) && !\is_string($raw['fragment'])) {
            throw new \InvalidArgumentException('Route config "fragment" must be a string.');
        }

        return new self(
            pid:        $pid,
            extension:  $raw['extension'] ?? null,
            plugin:     $raw['plugin'] ?? null,
            controller: $raw['controller'] ?? null,
            action:     $raw['action'] ?? null,
            arguments:  $raw['arguments'] ?? [],
            parameters: $raw['parameters'] ?? [],
            absolute:   (bool)($raw['absolute'] ?? true),
            fragment:   $raw['fragment'] ?? null,
        );
    }
}
