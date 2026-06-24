<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Serializer\FileProcessing;

use MaikSchneider\TcaApi\Configuration\ColumnDefinition;
use MaikSchneider\TcaApi\Configuration\ImageDefinition;
use TYPO3\CMS\Core\Imaging\ImageManipulation\Area;
use TYPO3\CMS\Core\Imaging\ImageManipulation\CropVariantCollection;
use TYPO3\CMS\Core\Resource\FileReference;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Service\ImageService;

/**
 * Processes a FAL FileReference into a serializable image array.
 *
 * By default this processor is used for all type=file TCA columns that do not
 * configure an explicit 'processor'.  Options are controlled through the 'image'
 * sub-key of the column definition:
 *
 *   'profile_photo' => [
 *       'groups' => ['list', 'show'],
 *       'image'  => [
 *           'maxWidth'      => 1200,
 *           'maxHeight'     => 800,
 *           'fileExtension' => 'webp',
 *           // optional — select a single crop variant:
 *           'cropVariant'   => 'default',
 *       ],
 *   ],
 *
 * Output shapes:
 *
 *   Without cropVariant (or cropVariant = null):
 *     { publicUrl, mimeType, fileSize, metadata, cropVariants: { default: { publicUrl, width, height }, … } }
 *
 *   With cropVariant set:
 *     { publicUrl, mimeType, fileSize, metadata, width, height }
 *     (publicUrl is the processed variant URL; no cropVariants key)
 */
final class ImageProcessor implements FileProcessorInterface
{
    protected ImageService $imageService;

    public function __construct()
    {
        $this->imageService = GeneralUtility::makeInstance(ImageService::class);
    }

    public function process(FileReference $fileReference, ColumnDefinition $columnConfig): array
    {
        $base    = GeneralUtility::makeInstance(FileProcessor::class)->process($fileReference, $columnConfig);
        $imageDef = $columnConfig->image ?? new ImageDefinition();

        $base['metadata'] = [
            'title'       => $base['metadata']['title'],
            'alternative' => $fileReference->getAlternative() ?: null,
            'description' => $base['metadata']['description'],
            'copyright'   => $fileReference->getOriginalFile()->getProperty('copyright') ?: null,
        ];

        $cropJson = (string)($fileReference->getProperty('crop') ?? '');

        if ($imageDef->cropVariant !== null) {
            // Single-variant mode: process the selected crop and inline the result.
            return $this->processSingleVariant($fileReference, $base, $cropJson, $imageDef);
        }

        // All-variants mode: process every stored crop variant.
        $base['cropVariants'] = $cropJson !== ''
            ? $this->buildAllCropVariants($fileReference, $cropJson, $imageDef)
            : [];

        return $base;
    }

    /**
     * Processes one named crop variant and returns a flat result without a cropVariants key.
     * Falls back to the uncropped image when the crop JSON is empty or the variant has no area.
     */
    private function processSingleVariant(
        FileReference $fileReference,
        array $base,
        string $cropJson,
        ImageDefinition $imageDef,
    ): array {
        $cropArea = $cropJson !== ''
            ? CropVariantCollection::create($cropJson)->getCropArea($imageDef->cropVariant)
            : Area::createEmpty();

        $processed = $this->imageService->applyProcessingInstructions(
            $fileReference,
            $this->buildInstructions($fileReference, $cropArea, $imageDef),
        );

        $base['publicUrl'] = UrlNormalizer::toRootRelative($this->imageService->getImageUri($processed, $imageDef->absolute));
        $base['width']     = (int)$processed->getProperty('width');
        $base['height']    = (int)$processed->getProperty('height');

        return $base;
    }

    /**
     * Processes every crop variant stored on the FileReference.
     */
    private function buildAllCropVariants(
        FileReference $fileReference,
        string $cropJson,
        ImageDefinition $imageDef,
    ): array {
        $collection  = CropVariantCollection::create($cropJson);
        $variantIds  = array_keys((array)json_decode($cropJson, true));
        $variants    = [];

        foreach ($variantIds as $variantId) {
            $cropArea  = $collection->getCropArea($variantId);
            $processed = $this->imageService->applyProcessingInstructions(
                $fileReference,
                $this->buildInstructions($fileReference, $cropArea, $imageDef),
            );

            $variants[$variantId] = [
                'publicUrl' => UrlNormalizer::toRootRelative($this->imageService->getImageUri($processed, $imageDef->absolute)),
                'width'     => (int)$processed->getProperty('width'),
                'height'    => (int)$processed->getProperty('height'),
            ];
        }

        return $variants;
    }

    /**
     * Builds the processing-instructions array from an ImageDefinition and a crop area.
     * Omits null values so TYPO3 applies its own defaults; passes crop only when non-empty.
     */
    private function buildInstructions(
        FileReference $fileReference,
        Area $cropArea,
        ImageDefinition $imageDef,
    ): array {
        $instructions = [
            'width'     => $imageDef->width,
            'height'    => $imageDef->height,
            'minWidth'  => $imageDef->minWidth,
            'minHeight' => $imageDef->minHeight,
            'maxWidth'  => $imageDef->maxWidth,
            'maxHeight' => $imageDef->maxHeight,
            'crop'      => $cropArea->isEmpty()
                ? null
                : $cropArea->makeAbsoluteBasedOnFile($fileReference->getOriginalFile()),
        ];

        if ($imageDef->fileExtension !== null) {
            $instructions['fileExtension'] = $imageDef->fileExtension;
        }

        return array_filter($instructions, static fn ($v) => $v !== null);
    }
}
