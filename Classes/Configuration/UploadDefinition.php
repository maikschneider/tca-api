<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Configuration;

/**
 * Typed value object for the 'upload' key in a column configuration.
 *
 * Presence of this object on a ColumnDefinition marks the column as accepting
 * file uploads via multipart/form-data requests. Absent means the column is
 * read-only for file data (existing behaviour).
 *
 * Allowed file types and extensions are read from the TCA column's own
 * `type=file` configuration at validation time — they are not duplicated here.
 */
final readonly class UploadDefinition
{
    /** Valid duplication behaviour values matching TYPO3's DuplicationBehavior enum. */
    private const VALID_DUPLICATION = ['rename', 'replace', 'cancel'];

    /**
     * @param string   $folder      FAL storage reference, e.g. '1:/uploads/'
     * @param int|null $maxSize     Maximum file size in bytes; null = unlimited
     * @param string   $duplication How to handle filename collisions: 'rename'|'replace'|'cancel'
     */
    public function __construct(
        public readonly string $folder,
        public readonly ?int $maxSize,
        public readonly string $duplication,
    ) {
    }

    /**
     * Parse and validate a raw upload config array.
     *
     * @throws \InvalidArgumentException on any invalid field.
     */
    public static function fromArray(array $raw): self
    {
        // ── folder ───────────────────────────────────────────────────────
        $folder = $raw['folder'] ?? null;
        if (!\is_string($folder) || $folder === '') {
            throw new \InvalidArgumentException(
                'Upload config "folder" is required and must be a non-empty string (e.g. "1:/uploads/").',
            );
        }
        if (!preg_match('/^\d+:\//', $folder)) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Upload config "folder" must start with a FAL storage reference like "1:/" — got "%s".',
                    $folder,
                ),
            );
        }

        // ── maxSize ──────────────────────────────────────────────────────
        $maxSize = null;
        if (\array_key_exists('maxSize', $raw) && $raw['maxSize'] !== null) {
            $maxSizeRaw = $raw['maxSize'];
            if (\is_int($maxSizeRaw)) {
                $maxSize = $maxSizeRaw;
            } elseif (\is_string($maxSizeRaw) && $maxSizeRaw !== '') {
                $maxSize = self::parseSize($maxSizeRaw);
            } else {
                throw new \InvalidArgumentException(
                    'Upload config "maxSize" must be a positive integer (bytes) or a size string like "5M", "100K", "1G".',
                );
            }
            if ($maxSize <= 0) {
                throw new \InvalidArgumentException('Upload config "maxSize" must be a positive value.');
            }
        }

        // ── duplication ──────────────────────────────────────────────────
        $duplication = $raw['duplication'] ?? 'rename';
        if (!\is_string($duplication) || !\in_array($duplication, self::VALID_DUPLICATION, true)) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Upload config "duplication" must be one of: %s.',
                    implode(', ', self::VALID_DUPLICATION),
                ),
            );
        }

        return new self(
            folder:      $folder,
            maxSize:     $maxSize,
            duplication: $duplication,
        );
    }

    /**
     * Parse a human-readable size string to bytes.
     * Supports suffixes: K/KB, M/MB, G/GB (case-insensitive).
     *
     * @throws \InvalidArgumentException for unrecognised format.
     */
    private static function parseSize(string $size): int
    {
        $size  = trim($size);
        $num   = (float)$size;
        $upper = strtoupper($size);

        if (str_ends_with($upper, 'GB') || str_ends_with($upper, 'G')) {
            return (int)($num * 1_073_741_824);
        }
        if (str_ends_with($upper, 'MB') || str_ends_with($upper, 'M')) {
            return (int)($num * 1_048_576);
        }
        if (str_ends_with($upper, 'KB') || str_ends_with($upper, 'K')) {
            return (int)($num * 1_024);
        }
        if (is_numeric($size)) {
            return (int)$num;
        }

        throw new \InvalidArgumentException(
            sprintf('Upload config "maxSize" value "%s" is not a recognised size format.', $size),
        );
    }
}
