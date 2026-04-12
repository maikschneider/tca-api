<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\OperationHandler;

use MaikSchneider\TcaApi\DataAccess\FileUploadService;
use MaikSchneider\TcaApi\Serializer\HydraResponseBuilder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;

#[Autoconfigure(public: true)]
final class UploadFileHandler implements OperationHandlerInterface
{
    public function __construct(
        private readonly FileUploadService $fileUploadService,
        private readonly HydraResponseBuilder $hydraResponseBuilder,
    ) {
    }

    public function supports(ServerRequestInterface $request, string $operation, array $config): bool
    {
        return $operation === 'create' && ($config['general']['type'] ?? '') === 'fileUpload';
    }

    public function handle(ServerRequestInterface $request, array $config): ResponseInterface
    {
        $uploadedFile = $this->findFirstUploadedFile($request->getUploadedFiles());
        if ($uploadedFile === null) {
            return $this->hydraResponseBuilder->buildError(400, 'Missing uploaded file. Use multipart/form-data with field "file".');
        }

        if ($uploadedFile->getError() !== \UPLOAD_ERR_OK) {
            return $this->hydraResponseBuilder->buildError(400, $this->mapUploadError($uploadedFile->getError()));
        }

        try {
            $storageUid = isset($config['general']['storageUid']) ? (int)$config['general']['storageUid'] : null;
            $targetFolder = isset($config['general']['targetFolder']) ? (string)$config['general']['targetFolder'] : null;
            $file = $this->fileUploadService->upload($uploadedFile, $storageUid, $targetFolder);
        } catch (\Throwable $exception) {
            GeneralUtility::makeInstance(LogManager::class)
                ->getLogger(__CLASS__)
                ->error('File upload failed', ['exception' => $exception]);
            return $this->hydraResponseBuilder->buildError(500, 'File upload failed.', 'Upload Failed');
        }

        $resourceName = (string)($config['general']['resourceName'] ?? 'files');
        $resourceType = (string)($config['general']['resourceType'] ?? 'FileUpload');
        $location = '/_api/' . $resourceName . '/' . $file->getUid();

        return $this->hydraResponseBuilder->buildItem([
            '@type' => $resourceType,
            '@id' => $location,
            'uid' => (int)$file->getUid(),
            'name' => $file->getName(),
            'identifier' => $file->getIdentifier(),
            'mimeType' => $file->getMimeType(),
            'size' => $file->getSize(),
        ])->withStatus(201)->withHeader('Location', $location);
    }

    public function getPriority(): int
    {
        return 20;
    }

    /**
     * @param array<string, UploadedFileInterface|array<mixed>> $uploadedFiles
     */
    private function findFirstUploadedFile(array $uploadedFiles): ?UploadedFileInterface
    {
        foreach ($uploadedFiles as $value) {
            if ($value instanceof UploadedFileInterface) {
                return $value;
            }

            if (\is_array($value)) {
                $found = $this->findFirstUploadedFile($value);
                if ($found instanceof UploadedFileInterface) {
                    return $found;
                }
            }
        }

        return null;
    }

    private function mapUploadError(int $errorCode): string
    {
        return match ($errorCode) {
            \UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE => 'Uploaded file exceeds the configured size limit.',
            \UPLOAD_ERR_PARTIAL => 'Uploaded file was only partially uploaded.',
            \UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
            \UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder for uploads.',
            \UPLOAD_ERR_CANT_WRITE => 'Failed to write uploaded file to disk.',
            \UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload.',
            default => 'Unknown file upload error.',
        };
    }
}
