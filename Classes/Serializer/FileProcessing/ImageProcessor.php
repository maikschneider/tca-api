<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Serializer\FileProcessing;

use MaikSchneider\TcaApi\Configuration\ColumnDefinition;
use TYPO3\CMS\Core\Imaging\ImageManipulation\CropVariantCollection;
use TYPO3\CMS\Core\Resource\FileReference;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Service\ImageService;

final class ImageProcessor implements FileProcessorInterface
{
    protected ImageService $imageService;

    public function __construct()
    {
        $this->imageService = GeneralUtility::makeInstance(ImageService::class);
    }

    public function process(FileReference $fileReference, ColumnDefinition $columnConfig): array
    {
        $base      = GeneralUtility::makeInstance(FileProcessor::class)->process($fileReference, $columnConfig);
        // @TODO: Make these configurable via ColumnDefinition, maybe with some presets like "thumbnail", "preview", "full"?
        $maxWidth  = 1024;
        $maxHeight = 1024;

        $base['metadata'] = [
            'title'       => $base['metadata']['title'],
            'alternative' => $fileReference->getAlternative() ?: null,
            'description' => $base['metadata']['description'],
            'copyright'   => $fileReference->getOriginalFile()->getProperty('copyright') ?: null,
        ];

        $cropJson             = (string)($fileReference->getProperty('crop') ?? '');
        $base['cropVariants'] = $cropJson !== ''
            ? $this->buildCropVariants($fileReference, $cropJson, $maxWidth, $maxHeight)
            : [];

        return $base;
    }

    private function buildCropVariants(
        FileReference $fileReference,
        string $cropJson,
        int $maxWidth,
        int $maxHeight,
    ): array {
        $collection = CropVariantCollection::create($cropJson);
        $variants   = [];

        foreach (array_keys($collection->asArray()) as $variantId) {
            $cropArea      = $collection->getCropArea($variantId);
            $processingInstructions= [
                'crop'      => $cropArea->makeAbsoluteBasedOnFile($fileReference->getOriginalFile()),
                'maxWidth'  => $maxWidth,
                'maxHeight' => $maxHeight,
            ];
            $processedFile = $this->imageService->applyProcessingInstructions($fileReference->getOriginalFile(), $processingInstructions);

            $variants[$variantId] = [
                'publicUrl' => $processedFile->getPublicUrl(),
                'width'     => (int)$processedFile->getProperty('width'),
                'height'    => (int)$processedFile->getProperty('height'),
            ];
        }

        return $variants;
    }
}
