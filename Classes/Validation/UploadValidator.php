<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Validation;

use MaikSchneider\TcaApi\Configuration\UploadDefinition;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Validates a PSR-7 uploaded file against an UploadDefinition constraint set.
 *
 * Returns violation arrays in the same format as FieldValidator so callers
 * can merge all violations before sending a single 422 response.
 */
final class UploadValidator
{
    /**
     * @return list<array{propertyPath: string, message: string, code: string}>
     */
    public function validate(
        UploadedFileInterface $file,
        UploadDefinition $upload,
        string $column,
    ): array {
        // Upload transport error takes priority — nothing else is reliable.
        if ($file->getError() !== \UPLOAD_ERR_OK) {
            return [$this->buildViolation(
                $column,
                sprintf("File upload for '%s' failed (error code %d).", $column, $file->getError()),
                'UPLOAD_ERROR',
            )];
        }

        $violations = [];

        // ── MIME type ────────────────────────────────────────────────────
        if ($upload->allowed !== []) {
            $mime = $file->getClientMediaType() ?? '';
            if (!$this->mimeMatches($mime, $upload->allowed)) {
                $violations[] = $this->buildViolation(
                    $column,
                    sprintf(
                        "File type '%s' is not allowed for '%s'. Allowed: %s.",
                        $mime !== '' ? $mime : 'unknown',
                        $column,
                        implode(', ', $upload->allowed),
                    ),
                    'UPLOAD_MIME_TYPE',
                );
            }
        }

        // ── File size ────────────────────────────────────────────────────
        if ($upload->maxSize !== null) {
            $size = $file->getSize();
            if ($size !== null && $size > $upload->maxSize) {
                $violations[] = $this->buildViolation(
                    $column,
                    sprintf(
                        "File for '%s' exceeds the maximum allowed size of %s.",
                        $column,
                        $this->formatBytes($upload->maxSize),
                    ),
                    'UPLOAD_MAX_SIZE',
                );
            }
        }

        return $violations;
    }

    /**
     * Check whether a MIME type matches any entry in the allowlist.
     * Supports wildcard suffixes such as 'image/*'.
     */
    private function mimeMatches(string $mime, array $allowed): bool
    {
        $mime = strtolower($mime);
        foreach ($allowed as $pattern) {
            $pattern = strtolower($pattern);
            if ($pattern === $mime) {
                return true;
            }
            // 'image/*' → match any 'image/...'
            if (str_ends_with($pattern, '/*')) {
                $base = substr($pattern, 0, -2);
                if (str_starts_with($mime, $base . '/')) {
                    return true;
                }
            }
        }
        return false;
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1_073_741_824) {
            return round($bytes / 1_073_741_824, 1) . ' GB';
        }
        if ($bytes >= 1_048_576) {
            return round($bytes / 1_048_576, 1) . ' MB';
        }
        if ($bytes >= 1_024) {
            return round($bytes / 1_024, 1) . ' KB';
        }
        return $bytes . ' B';
    }

    /** @return array{propertyPath: string, message: string, code: string} */
    private function buildViolation(string $column, string $message, string $code): array
    {
        return ['propertyPath' => $column, 'message' => $message, 'code' => $code];
    }
}
