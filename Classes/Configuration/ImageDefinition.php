<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Configuration;

/**
 * Typed configuration for image processing options in a column definition.
 *
 * Maps closely to the arguments of the Fluid f:image ViewHelper, allowing
 * consuming extensions to control how FAL images are processed and serialized.
 *
 * Usage in Configuration/TcaApi/MyResource.php:
 *
 *   'image_column' => [
 *       'groups'    => ['list', 'show'],
 *       'image'     => [
 *           'maxWidth'     => 1200,
 *           'maxHeight'    => 800,
 *           'fileExtension' => 'webp',
 *           'cropVariant'  => 'default',  // single variant → flat output
 *       ],
 *   ],
 *
 * When 'cropVariant' is omitted or null, all defined crop variants are processed
 * and returned as a 'cropVariants' map.  When 'cropVariant' is a string, only
 * that variant is processed and the result is inlined as the top-level image
 * (publicUrl, width, height — no cropVariants key).
 */
final readonly class ImageDefinition
{
    /**
     * @param string|null $width         Target width. Accepts TYPO3 imgResource notation:
     *                                   plain integer ("400"), crop-scale ("400c"), or
     *                                   scale-down-only ("400m").
     * @param string|null $height        Target height — same notation as $width.
     * @param int|null    $minWidth      Minimum width in pixels.
     * @param int|null    $minHeight     Minimum height in pixels.
     * @param int|null    $maxWidth      Maximum width in pixels.
     * @param int|null    $maxHeight     Maximum height in pixels.
     * @param string|null $cropVariant   Crop variant identifier to use.
     *                                   null  → all variants returned in a 'cropVariants' map.
     *                                   'default' (or any other id) → only that variant is
     *                                   processed; the URL is inlined as top-level 'publicUrl'.
     * @param string|null $fileExtension Target file extension for format conversion (e.g. 'webp').
     * @param bool        $absolute      Force an absolute URL. Defaults to false.
     */
    public function __construct(
        public readonly ?string $width = null,
        public readonly ?string $height = null,
        public readonly ?int $minWidth = null,
        public readonly ?int $minHeight = null,
        public readonly ?int $maxWidth = null,
        public readonly ?int $maxHeight = null,
        public readonly ?string $cropVariant = null,
        public readonly ?string $fileExtension = null,
        public readonly bool $absolute = false,
    ) {
    }

    /**
     * Normalise a raw 'image' config array and return a typed ImageDefinition.
     *
     * @throws \InvalidArgumentException on invalid values.
     */
    public static function fromArray(array $raw): self
    {
        // ── width / height ───────────────────────────────────────────────
        foreach (['width', 'height'] as $dim) {
            if (isset($raw[$dim])) {
                if (!\is_string($raw[$dim]) && !\is_int($raw[$dim])) {
                    throw new \InvalidArgumentException(
                        sprintf('Image config "%s" must be a string (e.g. "400", "400c", "400m").', $dim),
                    );
                }
                // Coerce int to string so the DTO always stores strings
                $raw[$dim] = (string)$raw[$dim];
                if (!\preg_match('/^\d+[cCmM]?$/', $raw[$dim])) {
                    throw new \InvalidArgumentException(
                        sprintf(
                            'Image config "%s" has invalid value "%s". '
                            . 'Use a positive integer, optionally followed by "c" (crop-scale) or "m" (scale-down-only).',
                            $dim,
                            $raw[$dim],
                        ),
                    );
                }
            }
        }

        // ── minWidth / minHeight / maxWidth / maxHeight ──────────────────
        foreach (['minWidth', 'minHeight', 'maxWidth', 'maxHeight'] as $intDim) {
            if (isset($raw[$intDim])) {
                if (!\is_int($raw[$intDim]) || $raw[$intDim] < 1) {
                    throw new \InvalidArgumentException(
                        sprintf('Image config "%s" must be a positive integer.', $intDim),
                    );
                }
            }
        }

        // ── cropVariant ──────────────────────────────────────────────────
        if (isset($raw['cropVariant'])) {
            if (!\is_string($raw['cropVariant']) || $raw['cropVariant'] === '') {
                throw new \InvalidArgumentException('Image config "cropVariant" must be a non-empty string.');
            }
        }

        // ── fileExtension ────────────────────────────────────────────────
        if (isset($raw['fileExtension'])) {
            if (!\is_string($raw['fileExtension']) || $raw['fileExtension'] === '') {
                throw new \InvalidArgumentException('Image config "fileExtension" must be a non-empty string.');
            }
        }

        // ── absolute ─────────────────────────────────────────────────────
        if (isset($raw['absolute']) && !\is_bool($raw['absolute'])) {
            throw new \InvalidArgumentException('Image config "absolute" must be a boolean.');
        }

        return new self(
            width:         $raw['width'] ?? null,
            height:        $raw['height'] ?? null,
            minWidth:      $raw['minWidth'] ?? null,
            minHeight:     $raw['minHeight'] ?? null,
            maxWidth:      $raw['maxWidth'] ?? null,
            maxHeight:     $raw['maxHeight'] ?? null,
            cropVariant:   $raw['cropVariant'] ?? null,
            fileExtension: $raw['fileExtension'] ?? null,
            absolute:      (bool)($raw['absolute'] ?? false),
        );
    }
}
