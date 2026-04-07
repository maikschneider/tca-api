<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api;

use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;

/**
 * Functional tests for the validation layer.
 *
 * Drives implementation of:
 * - Structured 422 responses with a `violations` array
 * - Field-level `propertyPath`, `message`, and `code` per violation
 * - `maxLength` validator type (config: validators[]['type' => 'maxLength', 'max' => N])
 * - Consistent validation across POST, PUT, and PATCH
 *
 * Articles config: title maxLength = 20, required = true.
 */
final class ValidationTest extends ApiFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/articles.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/fe_users.csv');
    }

    // ── 422 response structure ────────────────────────────────────────────────

    public function test422ResponseBodyHasViolationsKey(): void
    {
        $response = $this->executeApiWriteRequestAs('POST', '/_api/articles', 1, []);
        $body = $this->decodeResponseBody($response);

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('violations', $body);
    }

    public function test422ViolationHasPropertyPath(): void
    {
        $response = $this->executeApiWriteRequestAs('POST', '/_api/articles', 1, []);
        $body = $this->decodeResponseBody($response);

        self::assertSame('title', $body['violations'][0]['propertyPath']);
    }

    public function test422ViolationHasMessage(): void
    {
        $response = $this->executeApiWriteRequestAs('POST', '/_api/articles', 1, []);
        $body = $this->decodeResponseBody($response);

        self::assertNotEmpty($body['violations'][0]['message']);
    }

    public function test422HydraTitleIndicatesValidationFailed(): void
    {
        $response = $this->executeApiWriteRequestAs('POST', '/_api/articles', 1, []);
        $body = $this->decodeResponseBody($response);

        self::assertSame('Validation Failed', $body['hydra:title']);
    }

    // ── maxLength validator ───────────────────────────────────────────────────

    public function testPostReturns422WhenTitleExceedsMaxLength(): void
    {
        $response = $this->executeApiWriteRequestAs('POST', '/_api/articles', 1, [
            'title' => str_repeat('a', 21),
        ]);

        self::assertSame(422, $response->getStatusCode());
    }

    public function testPostReturns201WhenTitleWithinMaxLength(): void
    {
        $response = $this->executeApiWriteRequestAs('POST', '/_api/articles', 1, [
            'title' => str_repeat('a', 20),
        ]);

        self::assertSame(201, $response->getStatusCode());
    }

    public function testMaxLengthViolationIdentifiesProperty(): void
    {
        $response = $this->executeApiWriteRequestAs('POST', '/_api/articles', 1, [
            'title' => str_repeat('a', 21),
        ]);
        $body = $this->decodeResponseBody($response);

        self::assertSame('title', $body['violations'][0]['propertyPath']);
    }

    // ── PUT validates with the same rules ────────────────────────────────────

    public function testPutReturns422WhenTitleExceedsMaxLength(): void
    {
        $response = $this->executeApiWriteRequestAs('PUT', '/_api/articles/1', 1, [
            'title' => str_repeat('a', 21),
        ]);

        self::assertSame(422, $response->getStatusCode());
    }

    public function testPutViolationHasPropertyPath(): void
    {
        $response = $this->executeApiWriteRequestAs('PUT', '/_api/articles/1', 1, [
            'title' => str_repeat('a', 21),
        ]);
        $body = $this->decodeResponseBody($response);

        self::assertSame('title', $body['violations'][0]['propertyPath']);
    }

    // ── PATCH validates supplied fields ──────────────────────────────────────

    public function testPatchReturns422WhenSuppliedTitleExceedsMaxLength(): void
    {
        $response = $this->executeApiWriteRequestAs('PATCH', '/_api/articles/1', 1, [
            'title' => str_repeat('a', 21),
        ]);

        self::assertSame(422, $response->getStatusCode());
    }
}
