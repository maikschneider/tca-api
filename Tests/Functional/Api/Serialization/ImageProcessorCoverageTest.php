<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api\Serialization;

use MaikSchneider\TcaApi\Enum\AccessRole;
use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;

/**
 * Functional tests for ImageProcessor covering buildInstructions() and
 * processSingleVariant() — the two methods not reached by other test suites.
 *
 * Fixture data:
 *   Article 410 → profile_photo = sys_file_reference uid=10 (image/jpeg)
 *     – sys_file_reference has a valid crop JSON with "default" and "mobile" variants
 *
 * Scenarios:
 *   1. Single crop variant mode (cropVariant = 'default'):
 *      → processSingleVariant() is called; output is flat (publicUrl, width, height).
 *   2. All variants mode with crop JSON present:
 *      → buildAllCropVariants()/buildInstructions() called for each variant.
 *   3. Image options (maxWidth, maxHeight, fileExtension):
 *      → buildInstructions() includes those keys.
 */
final class ImageProcessorCoverageTest extends ApiFunctionalTestCase
{
    private const BASE_CONFIG = [
        'general' => [
            'table'        => 'tx_myext_domain_model_article',
            'resourceName' => 'articles',
            'resourceType' => 'Article',
            'operations'   => ['list', 'show'],
            'storagePid'   => 1,
        ],
        'order' => [
            'allowed' => ['uid'],
            'default' => ['uid' => 'asc'],
        ],
        'security' => [
            'list' => AccessRole::PUBLIC,
            'show' => AccessRole::PUBLIC,
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/articles_with_files.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/sys_file.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/sys_file_reference_with_crop.csv');
    }

    // ── processSingleVariant: cropVariant = 'default' ────────────────────────

    public function testSingleCropVariantReturnsFlatResult(): void
    {
        $config = self::BASE_CONFIG;
        $config['columns'] = [
            'profile_photo' => [
                'groups' => ['show'],
                'image'  => [
                    'cropVariant' => 'default',
                ],
            ],
        ];

        $this->registerResource('articles', $config);

        $response = $this->executeApiRequest('/_api/articles/410');
        $body     = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertArrayHasKey('profile_photo', $body);
        self::assertIsArray($body['profile_photo']);

        // processSingleVariant inlines width/height at the top level
        self::assertArrayHasKey('width', $body['profile_photo']);
        self::assertArrayHasKey('height', $body['profile_photo']);
        // No cropVariants key in single-variant mode
        self::assertArrayNotHasKey('cropVariants', $body['profile_photo']);
    }

    public function testSingleCropVariantHasPublicUrl(): void
    {
        $config = self::BASE_CONFIG;
        $config['columns'] = [
            'profile_photo' => [
                'groups' => ['show'],
                'image'  => [
                    'cropVariant' => 'default',
                ],
            ],
        ];

        $this->registerResource('articles', $config);

        $response = $this->executeApiRequest('/_api/articles/410');
        $body     = $this->decodeResponseBody($response);

        self::assertArrayHasKey('publicUrl', $body['profile_photo']);
        self::assertIsString($body['profile_photo']['publicUrl']);
    }

    public function testSingleCropVariantHasIntegerDimensions(): void
    {
        $config = self::BASE_CONFIG;
        $config['columns'] = [
            'profile_photo' => [
                'groups' => ['show'],
                'image'  => [
                    'cropVariant' => 'default',
                ],
            ],
        ];

        $this->registerResource('articles', $config);

        $response = $this->executeApiRequest('/_api/articles/410');
        $body     = $this->decodeResponseBody($response);

        self::assertIsInt($body['profile_photo']['width']);
        self::assertIsInt($body['profile_photo']['height']);
    }

    public function testSingleCropVariantHasMetadata(): void
    {
        $config = self::BASE_CONFIG;
        $config['columns'] = [
            'profile_photo' => [
                'groups' => ['show'],
                'image'  => [
                    'cropVariant' => 'default',
                ],
            ],
        ];

        $this->registerResource('articles', $config);

        $response = $this->executeApiRequest('/_api/articles/410');
        $body     = $this->decodeResponseBody($response);

        self::assertArrayHasKey('metadata', $body['profile_photo']);
        self::assertArrayHasKey('title', $body['profile_photo']['metadata']);
        self::assertArrayHasKey('alternative', $body['profile_photo']['metadata']);
        self::assertArrayHasKey('description', $body['profile_photo']['metadata']);
        self::assertArrayHasKey('copyright', $body['profile_photo']['metadata']);
    }

    // ── processSingleVariant with 'mobile' variant ───────────────────────────

    public function testSingleCropVariantMobileReturnsFlatResult(): void
    {
        $config = self::BASE_CONFIG;
        $config['columns'] = [
            'profile_photo' => [
                'groups' => ['show'],
                'image'  => [
                    'cropVariant' => 'mobile',
                ],
            ],
        ];

        $this->registerResource('articles', $config);

        $response = $this->executeApiRequest('/_api/articles/410');
        $body     = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertArrayHasKey('width', $body['profile_photo']);
        self::assertArrayHasKey('height', $body['profile_photo']);
        self::assertArrayNotHasKey('cropVariants', $body['profile_photo']);
    }

    // ── buildAllCropVariants with crop JSON present ──────────────────────────

    public function testAllVariantsWithCropJsonReturnsVariantsMap(): void
    {
        $config = self::BASE_CONFIG;
        $config['columns'] = [
            'profile_photo' => [
                'groups' => ['show'],
                // No cropVariant → all-variants mode
            ],
        ];

        $this->registerResource('articles', $config);

        $response = $this->executeApiRequest('/_api/articles/410');
        $body     = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertArrayHasKey('cropVariants', $body['profile_photo']);
        self::assertIsArray($body['profile_photo']['cropVariants']);
    }

    public function testAllVariantsContainsDefaultAndMobileKeys(): void
    {
        $config = self::BASE_CONFIG;
        $config['columns'] = [
            'profile_photo' => [
                'groups' => ['show'],
            ],
        ];

        $this->registerResource('articles', $config);

        $response = $this->executeApiRequest('/_api/articles/410');
        $body     = $this->decodeResponseBody($response);

        $variants = $body['profile_photo']['cropVariants'];
        self::assertArrayHasKey('default', $variants);
        self::assertArrayHasKey('mobile', $variants);
    }

    public function testAllVariantsEachHasPublicUrlWidthHeight(): void
    {
        $config = self::BASE_CONFIG;
        $config['columns'] = [
            'profile_photo' => [
                'groups' => ['show'],
            ],
        ];

        $this->registerResource('articles', $config);

        $response = $this->executeApiRequest('/_api/articles/410');
        $body     = $this->decodeResponseBody($response);

        foreach ($body['profile_photo']['cropVariants'] as $variantId => $variant) {
            self::assertArrayHasKey('publicUrl', $variant, "Variant '$variantId' missing publicUrl");
            self::assertArrayHasKey('width', $variant, "Variant '$variantId' missing width");
            self::assertArrayHasKey('height', $variant, "Variant '$variantId' missing height");
            self::assertIsInt($variant['width'], "Variant '$variantId' width should be int");
            self::assertIsInt($variant['height'], "Variant '$variantId' height should be int");
        }
    }

    // ── buildInstructions with image options ─────────────────────────────────

    public function testSingleVariantWithMaxWidthMaxHeight(): void
    {
        $config = self::BASE_CONFIG;
        $config['columns'] = [
            'profile_photo' => [
                'groups' => ['show'],
                'image'  => [
                    'cropVariant' => 'default',
                    'maxWidth'    => 200,
                    'maxHeight'   => 150,
                ],
            ],
        ];

        $this->registerResource('articles', $config);

        $response = $this->executeApiRequest('/_api/articles/410');
        $body     = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        // The processed image should have dimensions; exact values depend on
        // the TYPO3 processing pipeline, but width/height must be present.
        self::assertArrayHasKey('width', $body['profile_photo']);
        self::assertArrayHasKey('height', $body['profile_photo']);
        self::assertIsInt($body['profile_photo']['width']);
        self::assertIsInt($body['profile_photo']['height']);
    }

    public function testAllVariantsWithMaxWidthOption(): void
    {
        $config = self::BASE_CONFIG;
        $config['columns'] = [
            'profile_photo' => [
                'groups' => ['show'],
                'image'  => [
                    'maxWidth' => 300,
                ],
            ],
        ];

        $this->registerResource('articles', $config);

        $response = $this->executeApiRequest('/_api/articles/410');
        $body     = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertArrayHasKey('cropVariants', $body['profile_photo']);
        // Each variant should still have valid structure
        foreach ($body['profile_photo']['cropVariants'] as $variant) {
            self::assertArrayHasKey('publicUrl', $variant);
            self::assertArrayHasKey('width', $variant);
            self::assertArrayHasKey('height', $variant);
        }
    }

    public function testSingleVariantWithWidthAndHeight(): void
    {
        $config = self::BASE_CONFIG;
        $config['columns'] = [
            'profile_photo' => [
                'groups' => ['show'],
                'image'  => [
                    'cropVariant' => 'default',
                    'width'       => '100',
                    'height'      => '100',
                ],
            ],
        ];

        $this->registerResource('articles', $config);

        $response = $this->executeApiRequest('/_api/articles/410');
        $body     = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertArrayHasKey('width', $body['profile_photo']);
        self::assertArrayHasKey('height', $body['profile_photo']);
        self::assertArrayNotHasKey('cropVariants', $body['profile_photo']);
    }

    public function testSingleVariantWithMinWidthMinHeight(): void
    {
        $config = self::BASE_CONFIG;
        $config['columns'] = [
            'profile_photo' => [
                'groups' => ['show'],
                'image'  => [
                    'cropVariant' => 'default',
                    'minWidth'    => 50,
                    'minHeight'   => 50,
                ],
            ],
        ];

        $this->registerResource('articles', $config);

        $response = $this->executeApiRequest('/_api/articles/410');
        $body     = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertArrayHasKey('width', $body['profile_photo']);
        self::assertArrayHasKey('height', $body['profile_photo']);
    }
}
