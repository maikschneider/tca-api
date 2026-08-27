<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Frontend;

use MaikSchneider\TcaApi\Exception\UnknownResourceException;
use MaikSchneider\TcaApi\Frontend\TcaApiRepository;
use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;

/**
 * Functional tests for {@see TcaApiRepository} — the frontend data layer that
 * returns clean PHP arrays (JSON-LD envelope stripped) for server-side rendering.
 *
 * Uses the articles fixture (3 visible records, uid 1-3) with a shallow color_id
 * relation (article 1 → color 1), which serializes as a plain IRI string, to
 * confirm relation references survive normalization untouched.
 */
final class TcaApiRepositoryTest extends ApiFunctionalTestCase
{
    private TcaApiRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/articles.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/colors.csv');
        $this->repository = $this->get(TcaApiRepository::class);
    }

    public function testCollectionReturnsCleanArrays(): void
    {
        $items = $this->repository->collection('articles', fields: ['title', 'color_id']);

        self::assertCount(3, $items);
        self::assertSame(1, $items[0]['uid']);
        self::assertSame('First Article', $items[0]['title']);

        // A shallow (non-embedded) hasOne relation serializes as a plain IRI string
        // and survives normalization unchanged; only JSON-LD envelope *keys* are stripped.
        self::assertSame('/_api/colors/1', $items[0]['color_id']);
        $this->assertNoJsonLdKeys($items);
    }

    public function testFindReturnsCleanRecordOrNull(): void
    {
        $record = $this->repository->find('articles', 1, ['title']);
        self::assertNotNull($record);
        self::assertSame(1, $record['uid']);
        self::assertSame('First Article', $record['title']);
        $this->assertNoJsonLdKeys($record);

        self::assertNull($this->repository->find('articles', 999));
    }

    public function testCollectionResultCarriesPagination(): void
    {
        $result = $this->repository->collectionResult('articles', itemsPerPage: 2, fields: ['title']);

        self::assertCount(2, $result['items']);
        self::assertSame([
            'page'         => 1,
            'itemsPerPage' => 2,
            'total'        => 3,
            'totalPages'   => 2,
        ], $result['pagination']);
        $this->assertNoJsonLdKeys($result['items']);
    }

    public function testUnknownResourceThrows(): void
    {
        $this->expectException(UnknownResourceException::class);
        $this->repository->collection('does-not-exist');
    }

    /**
     * Recursively assert that no array key is a JSON-LD/Hydra envelope key.
     *
     * @param array<array-key, mixed> $data
     */
    private function assertNoJsonLdKeys(array $data): void
    {
        foreach ($data as $key => $value) {
            if (\is_string($key)) {
                self::assertFalse(
                    str_starts_with($key, '@') || str_starts_with($key, 'hydra:'),
                    sprintf('Unexpected JSON-LD envelope key "%s" in clean array output.', $key),
                );
            }
            if (\is_array($value)) {
                $this->assertNoJsonLdKeys($value);
            }
        }
    }
}
