<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\OperationHandler;

use MaikSchneider\TcaApi\Serializer\HydraResponseBuilder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use TYPO3\CMS\Core\Http\UploadedFile;
use TYPO3\CMS\Core\Resource\Enum\DuplicationBehavior;
use TYPO3\CMS\Core\Resource\Index\MetaDataRepository;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Validation\Validator\FileExtensionValidator;
use TYPO3\CMS\Extbase\Validation\Validator\FileSizeValidator;
use TYPO3\CMS\Extbase\Validation\Validator\MimeTypeValidator;

#[Autoconfigure(public: true)]
final class FileUploadHandler implements OperationHandlerInterface
{
    public function __construct(
        private readonly ResourceFactory $resourceFactory,
        private readonly MetaDataRepository $metaDataRepository,
        private readonly HydraResponseBuilder $hydraResponseBuilder,
    ) {
    }

    public function supports(ServerRequestInterface $request, string $operation, array $config): bool
    {
        return $operation === 'upload';
    }

    public function handle(ServerRequestInterface $request, array $config): ResponseInterface
    {
        $uploadConfig = $config['upload'] ?? [];
        $metaConfig   = $config['metadata'] ?? [];
        $resourceName = $config['general']['resourceName'];

        // 1. Retrieve uploaded file from PSR-7 request
        $uploadedFiles = $request->getUploadedFiles();
        $uploadedFile  = $uploadedFiles[$resourceName] ?? $uploadedFiles['file'] ?? null;

        if (!$uploadedFile instanceof UploadedFile) {
            return $this->hydraResponseBuilder->buildError(
                400,
                'No file uploaded. Expected field name: "' . $resourceName . '" or "file".',
                'Bad Request',
            );
        }

        if ($uploadedFile->getError() !== UPLOAD_ERR_OK) {
            return $this->hydraResponseBuilder->buildError(
                400,
                'File upload error code: ' . $uploadedFile->getError(),
                'Bad Request',
            );
        }

        // 2. Validate file
        $violations = $this->validateUploadedFile($uploadedFile, $uploadConfig);
        if ($violations !== []) {
            return $this->hydraResponseBuilder->buildValidationError($violations);
        }

        // 3. Resolve target folder via FAL
        $uploadFolder = $uploadConfig['uploadFolder'] ?? '1:/user_upload/';
        $duplication  = DuplicationBehavior::from($uploadConfig['duplicationBehavior'] ?? 'rename');
        $folder       = $this->resourceFactory->getFolderObjectFromCombinedIdentifier($uploadFolder);

        // 4. Store file via FAL
        try {
            $file = $folder->addUploadedFile($uploadedFile, $duplication);
        } catch (\Exception $e) {
            return $this->hydraResponseBuilder->buildError(
                500,
                'File storage failed: ' . $e->getMessage(),
                'Internal Server Error',
            );
        }

        // 5. Collect metadata from request body
        $parsedBody  = (array)($request->getParsedBody() ?? []);
        $allowedMeta = $metaConfig['allowedFields'] ?? ['title', 'description', 'alternative'];
        $metaUpdate  = [];

        foreach ($allowedMeta as $field) {
            if (isset($parsedBody[$field])) {
                $metaUpdate[$field] = (string)$parsedBody[$field];
            }
        }

        // 6. Set owner if FE user is authenticated and ownership is enabled
        $feUser = $request->getAttribute('frontend.user');
        if ($feUser !== null && !empty($feUser->user['uid']) && ($config['ownership']['enabled'] ?? false)) {
            $metaUpdate['tx_tcaapi_owner'] = (int)$feUser->user['uid'];
        }

        if ($metaUpdate !== []) {
            $this->metaDataRepository->update($file->getUid(), $metaUpdate);
        }

        // 7. Build response
        $meta     = $this->metaDataRepository->findByFileUid($file->getUid());
        $response = [
            '@context'  => 'http://www.w3.org/ns/hydra/context.jsonld',
            '@type'     => $config['general']['resourceType'] ?? 'FileResource',
            '@id'       => '/_api/' . $resourceName . '/' . $file->getUid(),
            'uid'       => $file->getUid(),
            'publicUrl' => $file->getPublicUrl(),
            'fileName'  => $file->getName(),
            'mimeType'  => $file->getMimeType(),
            'fileSize'  => $file->getSize(),
            'metadata'  => [
                'title'           => $meta['title'] ?? null,
                'description'     => $meta['description'] ?? null,
                'alternative'     => $meta['alternative'] ?? null,
                'tx_tcaapi_owner' => (int)($meta['tx_tcaapi_owner'] ?? 0),
            ],
        ];

        $location = '/_api/' . $resourceName . '/' . $file->getUid();

        return $this->hydraResponseBuilder->buildItem($response)
            ->withStatus(201)
            ->withHeader('Location', $location);
    }

    public function getPriority(): int
    {
        return 10;
    }

    /**
     * @return list<array{propertyPath: string, message: string, code: string}>
     */
    private function validateUploadedFile(UploadedFile $file, array $uploadConfig): array
    {
        $violations = [];

        $allowedMimeTypes = $uploadConfig['allowedMimeTypes'] ?? [];
        if ($allowedMimeTypes !== []) {
            $validator = GeneralUtility::makeInstance(MimeTypeValidator::class);
            $validator->setOptions(['allowedMimeTypes' => $allowedMimeTypes]);
            $result = $validator->validate($file);
            foreach ($result->getErrors() as $error) {
                $violations[] = ['propertyPath' => 'file', 'message' => $error->getMessage(), 'code' => 'MIME_TYPE'];
            }
        }

        $maxFileSize = $uploadConfig['maxFileSize'] ?? null;
        if ($maxFileSize !== null && $violations === []) {
            $validator = GeneralUtility::makeInstance(FileSizeValidator::class);
            $validator->setOptions(['maximum' => $maxFileSize]);
            $result = $validator->validate($file);
            foreach ($result->getErrors() as $error) {
                $violations[] = ['propertyPath' => 'file', 'message' => $error->getMessage(), 'code' => 'FILE_SIZE'];
            }
        }

        $allowedExtensions = $uploadConfig['allowedExtensions'] ?? [];
        if ($allowedExtensions !== [] && $violations === []) {
            $validator = GeneralUtility::makeInstance(FileExtensionValidator::class);
            $validator->setOptions(['allowedFileExtensions' => $allowedExtensions]);
            $result = $validator->validate($file);
            foreach ($result->getErrors() as $error) {
                $violations[] = ['propertyPath' => 'file', 'message' => $error->getMessage(), 'code' => 'FILE_EXTENSION'];
            }
        }

        return $violations;
    }
}
