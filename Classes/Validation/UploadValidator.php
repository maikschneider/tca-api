<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Validation;

use MaikSchneider\TcaApi\Configuration\UploadDefinition;
use TYPO3\CMS\Core\Http\UploadedFile;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Validation\Validator\FileExtensionValidator;
use TYPO3\CMS\Extbase\Validation\Validator\FileSizeValidator;
use TYPO3\CMS\Extbase\Validation\Validator\MimeTypeValidator;

/**
 * Validates a TYPO3 UploadedFile against an UploadDefinition constraint set
 * using TYPO3's built-in Extbase validators.
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
        UploadedFile $file,
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

        // TYPO3 validators read from the actual file on disk via finfo.
        // If the UploadedFile was created from a stream (e.g. in tests) and has
        // no real temporary path, write the stream content to a real temp file so
        // validators can access it. The temp file is removed after validation.
        $tmpCreated = null;
        if ($file->getTemporaryFileName() === null) {
            $tmpCreated = tempnam(sys_get_temp_dir(), 'tca_api_upload_');
            $stream     = $file->getStream();
            $stream->rewind();
            file_put_contents($tmpCreated, (string)$stream);
            // Re-wrap as a file-path-based UploadedFile so validators can call getTemporaryFileName()
            $file = new UploadedFile(
                $tmpCreated,
                $file->getSize(),
                $file->getError(),
                $file->getClientFilename(),
                $file->getClientMediaType(),
            );
        }

        try {
            $violations = $this->runValidators($file, $upload, $column);
        } finally {
            if ($tmpCreated !== null && file_exists($tmpCreated)) {
                unlink($tmpCreated);
            }
        }

        return $violations;
    }

    /**
     * @return list<array{propertyPath: string, message: string, code: string}>
     */
    private function runValidators(UploadedFile $file, UploadDefinition $upload, string $column): array
    {
        $violations = [];

        // ── MIME type ────────────────────────────────────────────────────
        // MimeTypeValidator reads the actual file content via finfo — not the
        // client-provided media type. ignoreFileExtensionCheck is enabled so
        // extension checking can be handled separately via FileExtensionValidator.
        // Plain-string messages are used (no LLL: prefix) so that the validator
        // can operate without a bootstrapped localization stack.
        if ($upload->allowed !== []) {
            $validator = GeneralUtility::makeInstance(MimeTypeValidator::class);
            $validator->setOptions([
                'allowedMimeTypes'        => $upload->allowed,
                'ignoreFileExtensionCheck' => true,
                'notAllowedMessage'        => 'The file type is not allowed.',
            ]);
            foreach ($validator->validate($file)->getErrors() as $error) {
                $violations[] = $this->buildViolation($column, $error->getMessage(), 'UPLOAD_MIME_TYPE');
            }
        }

        // ── File size ────────────────────────────────────────────────────
        // FileSizeValidator expects a size string (e.g. '5242880B'); converting
        // the stored bytes integer to the 'NB' format satisfies its format check.
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
        if ($upload->allowedExtensions !== []) {
            $validator = GeneralUtility::makeInstance(FileExtensionValidator::class);
            $validator->setOptions([
                'allowedFileExtensions' => $upload->allowedExtensions,
                'notAllowedMessage'     => 'The file extension is not allowed.',
            ]);
            foreach ($validator->validate($file)->getErrors() as $error) {
                $violations[] = $this->buildViolation($column, $error->getMessage(), 'UPLOAD_EXTENSION');
            }
        }

        return $violations;
    }

    /** @return array{propertyPath: string, message: string, code: string} */
    private function buildViolation(string $column, string $message, string $code): array
    {
        return ['propertyPath' => $column, 'message' => $message, 'code' => $code];
    }
}
