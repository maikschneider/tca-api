<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Validation;

use MaikSchneider\TcaApi\Configuration\UploadDefinition;
use TYPO3\CMS\Core\Http\UploadedFile;
use TYPO3\CMS\Core\Resource\MimeTypeDetector;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Validation\Validator\FileExtensionValidator;
use TYPO3\CMS\Extbase\Validation\Validator\FileSizeValidator;
use TYPO3\CMS\Extbase\Validation\Validator\MimeTypeValidator;

/**
 * Validates a TYPO3 UploadedFile against an UploadDefinition constraint set
 * and the TCA type=file column definition.
 *
 * Allowed extensions and their derived MIME types are read from TCA at validation
 * time — they are not duplicated in the API column config.
 *
 * The caller (FileUploadTrait) is responsible for ensuring $file is backed by a
 * real file path (not a stream) before calling validate(). TYPO3's built-in
 * validators require getTemporaryFileName() to return a non-null path.
 *
 * Returns violation arrays in the same format as FieldValidator so callers
 * can merge all violations before sending a single 422 response.
 */
final class UploadValidator
{
    /**
     * Extensions that map to 'common-image-types' in TCA type=file columns.
     * Resolved at runtime from GFX.imagefile_ext, falling back to this constant.
     */
    private const FALLBACK_IMAGE_EXTENSIONS = 'gif,jpg,jpeg,tif,tiff,bmp,pcx,tga,png,pdf,ai,svg,webp,avif';

    /**
     * @return list<array{propertyPath: string, message: string, code: string}>
     */
    public function validate(
        UploadedFile $file,
        UploadDefinition $upload,
        string $table,
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

        return $this->runValidators($file, $upload, $table, $column);
    }

    /**
     * @return list<array{propertyPath: string, message: string, code: string}>
     */
    private function runValidators(
        UploadedFile $file,
        UploadDefinition $upload,
        string $table,
        string $column,
    ): array {
        $violations = [];
        $extensions = $this->resolveTcaExtensions($table, $column);

        // ── MIME type ────────────────────────────────────────────────────
        // Derive allowed MIME types from the TCA extensions via MimeTypeDetector.
        // MimeTypeValidator reads actual file content via finfo — more reliable
        // than extension-based checks alone.
        if ($extensions !== []) {
            $allowedMimeTypes = $this->extensionsToMimeTypes($extensions);
            if ($allowedMimeTypes !== []) {
                $validator = GeneralUtility::makeInstance(MimeTypeValidator::class);
                $validator->setOptions([
                    'allowedMimeTypes'         => $allowedMimeTypes,
                    'ignoreFileExtensionCheck' => true,
                    'notAllowedMessage'        => 'The file type is not allowed.',
                ]);
                foreach ($validator->validate($file)->getErrors() as $error) {
                    $violations[] = $this->buildViolation($column, $error->getMessage(), 'UPLOAD_MIME_TYPE');
                }
            }
        }

        // ── File size ────────────────────────────────────────────────────
        if ($upload->maxSize !== null) {
            $validator = GeneralUtility::makeInstance(FileSizeValidator::class);
            $validator->setOptions([
                'maximum'       => $upload->maxSize . 'B',
                'exceedMessage' => 'The file exceeds the maximum allowed size.',
            ]);
            foreach ($validator->validate($file)->getErrors() as $error) {
                $violations[] = $this->buildViolation($column, $error->getMessage(), 'UPLOAD_MAX_SIZE');
            }
        }

        // ── File extension ───────────────────────────────────────────────
        if ($extensions !== []) {
            $validator = GeneralUtility::makeInstance(FileExtensionValidator::class);
            $validator->setOptions([
                'allowedFileExtensions' => $extensions,
                'notAllowedMessage'     => 'The file extension is not allowed.',
            ]);
            foreach ($validator->validate($file)->getErrors() as $error) {
                $violations[] = $this->buildViolation($column, $error->getMessage(), 'UPLOAD_EXTENSION');
            }
        }

        return $violations;
    }

    /**
     * Read the list of allowed file extensions from the TCA type=file column.
     *
     * Handles the 'common-image-types' placeholder by resolving it via the
     * GFX.imagefile_ext site configuration (defaults to a safe fallback).
     *
     * @return string[] lowercase extension list; empty = unrestricted
     */
    private function resolveTcaExtensions(string $table, string $column): array
    {
        $tcaAllowed = $GLOBALS['TCA'][$table]['columns'][$column]['config']['allowed'] ?? '';

        if (!\is_string($tcaAllowed) || $tcaAllowed === '') {
            return [];
        }

        if (trim($tcaAllowed) === 'common-image-types') {
            $tcaAllowed = $GLOBALS['TYPO3_CONF_VARS']['GFX']['imagefile_ext']
                ?? self::FALLBACK_IMAGE_EXTENSIONS;
        }

        return array_filter(
            array_map(
                strtolower(...),
                GeneralUtility::trimExplode(',', $tcaAllowed, true),
            ),
        );
    }

    /**
     * Convert file extensions to a flat, unique list of MIME types.
     *
     * @param  string[] $extensions
     * @return string[]
     */
    private function extensionsToMimeTypes(array $extensions): array
    {
        $detector   = new MimeTypeDetector();
        $mimeTypes  = [];

        foreach ($extensions as $ext) {
            foreach ($detector->getMimeTypesForFileExtension($ext) as $mime) {
                $mimeTypes[$mime] = true;
            }
        }

        return array_keys($mimeTypes);
    }

    /** @return array{propertyPath: string, message: string, code: string} */
    private function buildViolation(string $column, string $message, string $code): array
    {
        return ['propertyPath' => $column, 'message' => $message, 'code' => $code];
    }
}
