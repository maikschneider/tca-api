<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api\SiteSettings;

use MaikSchneider\TcaApi\Configuration\ApiDefinition;
use MaikSchneider\TcaApi\OpenApi\OpenApiSchemasBuilder;
use MaikSchneider\TcaApi\Serializer\ResourceSerializer;
use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;

/**
 * Verifies that tca_api.allowedResources limits which resources are accessible.
 *
 * Site is configured with allowedResources: 'articles' — only articles are exposed.
 */
final class SiteSettingsAllowedResourcesTest extends ApiFunctionalTestCase
{
    protected array $pathsToLinkInTestInstance = [
        'typo3conf/ext/tca_api/Tests/Functional/Fixtures/Sites_Restricted' => 'typo3conf/sites',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/articles.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/colors.csv');
    }

    public function testAllowedResourceReturns200(): void
    {
        $response = $this->executeApiRequest('/_api/articles');

        self::assertSame(200, $response->getStatusCode());
    }

    public function testBlockedResourceReturns404(): void
    {
        $response = $this->executeApiRequest('/_api/colors');

        self::assertSame(404, $response->getStatusCode());
    }

    public function testRelationToBlockedResourceRendersUidNotIri(): void
    {
        $response = $this->executeApiRequest('/_api/articles');
        self::assertSame(200, $response->getStatusCode());

        $colorIds = array_column($this->decodeResponseBody($response)['hydra:member'], 'color_id');
        self::assertNotSame([], array_filter($colorIds), 'Fixture articles must reference colors');
        foreach ($colorIds as $colorId) {
            // /_api/colors/{uid} answers 404 on this site, so no IRI may point there.
            self::assertTrue($colorId === null || \is_int($colorId), 'color_id must be a uid, got ' . var_export($colorId, true));
        }
    }

    public function testEmbeddedBlockedResourceKeepsItsColumnsWithoutIri(): void
    {
        // Serialized directly: a sub-request reloads the configured definitions,
        // which would replace a registered "articles" resource.
        $articles = ApiDefinition::fromArray([
            'general' => [
                'table'        => 'tx_myext_domain_model_article',
                'resourceName' => 'embed-articles',
                'resourceType' => 'EmbedArticle',
                'operations'   => ['list', 'show'],
            ],
            'columns' => [
                'title'    => ['groups' => ['list', 'show']],
                'color_id' => ['groups' => ['list', 'show'], 'embed' => true],
            ],
        ]);
        $row = $this->getConnectionPool()->getConnectionForTable('tx_myext_domain_model_article')
            ->select(['*'], 'tx_myext_domain_model_article', ['uid' => 1])->fetchAssociative();
        self::assertIsArray($row);

        $color = $this->get(ResourceSerializer::class)
            ->serialize($row, $articles, '/_api/embed-articles', [], ['__allowedResources' => ['embed-articles']])['color_id'];

        // The colors resource exposes only "name": its column config still applies,
        // but without an item endpoint on this site there is no @id.
        self::assertSame(['@type', 'uid', 'name'], array_keys($color));

        $schemas = $this->get(OpenApiSchemasBuilder::class)->build([$articles], $this->getApiRegistry()->getAll());
        self::assertSame(['@type', 'uid', 'name'], array_keys($schemas['EmbedArticleRead']['properties']['color_id']['oneOf'][0]['properties']));
    }

    // ── Hydra docs do not leak blocked resource types ─────────────────────────

    public function testHydraDocsDoNotContainBlockedSupportedClass(): void
    {
        $response = $this->executeApiRequest('/_api/docs.jsonld');
        self::assertSame(200, $response->getStatusCode());

        $body = $this->decodeResponseBody($response);
        $classIds = array_column($body['hydra:supportedClass'], '@id');

        self::assertContains('#Article', $classIds, 'Article must be present');
        self::assertNotContains('#Color', $classIds, 'Color is blocked and must not appear in supportedClass');
    }

    public function testHydraDocsRelationRangeDoesNotPointToBlockedClass(): void
    {
        $response = $this->executeApiRequest('/_api/docs.jsonld');
        self::assertSame(200, $response->getStatusCode());

        $body = $this->decodeResponseBody($response);

        $articleClass = null;
        foreach ($body['hydra:supportedClass'] as $class) {
            if ($class['@id'] === '#Article') {
                $articleClass = $class;
                break;
            }
        }
        self::assertNotNull($articleClass, 'Article class must be present in docs');

        // color_id relates to the colors resource which is blocked — its range must not be #Color
        foreach ($articleClass['hydra:supportedProperty'] as $prop) {
            if ($prop['hydra:title'] === 'color_id') {
                $range = $prop['hydra:property']['range'] ?? null;
                self::assertNotSame('#Color', $range, 'color_id range must not reference the blocked Color class');
                return;
            }
        }
    }
}
