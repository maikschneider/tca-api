<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api\Write;

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
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/articles.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/fe_users.csv');
    }

    // ── 422 response structure (POST empty body) ───────────────────────────

    public function testPostWithMissingRequiredFieldReturns422WithViolations(): void
    {
        $response = $this->executeApiWriteRequestAs('POST', '/_api/articles', 1, []);
        $body = $this->decodeResponseBody($response);

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('violations', $body);
        self::assertSame('title', $body['violations'][0]['propertyPath']);
        self::assertNotEmpty($body['violations'][0]['message']);
        self::assertSame('Validation Failed', $body['hydra:title']);
    }

    // ── maxLength validator ───────────────────────────────────────────────────

    public function testPostReturns422WhenTitleExceedsMaxLength(): void
    {
        $response = $this->executeApiWriteRequestAs('POST', '/_api/articles', 1, [
            'title' => str_repeat('a', 21),
        ]);
        $body = $this->decodeResponseBody($response);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('title', $body['violations'][0]['propertyPath']);
    }

    public function testPostReturns201WhenTitleWithinMaxLength(): void
    {
        $response = $this->executeApiWriteRequestAs('POST', '/_api/articles', 1, [
            'title' => str_repeat('a', 20),
        ]);

        self::assertSame(201, $response->getStatusCode());
    }

    // ── PUT validates with the same rules ────────────────────────────────────

    public function testPutReturns422WhenTitleExceedsMaxLength(): void
    {
        $response = $this->executeApiWriteRequestAs('PUT', '/_api/articles/1', 1, [
            'title' => str_repeat('a', 21),
        ]);
        $body = $this->decodeResponseBody($response);

        self::assertSame(422, $response->getStatusCode());
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

    // ── minLength validator ───────────────────────────────────────────────────

    public function testPostReturns422WhenTitleBelowMinLength(): void
    {
        $response = $this->executeApiWriteRequestAs('POST', '/_api/articles', 1, [
            'title' => 'ab',
        ]);
        $body = $this->decodeResponseBody($response);

        self::assertSame(422, $response->getStatusCode());
        $codes = array_column($body['violations'], 'code');
        self::assertContains('MIN_LENGTH', $codes);
    }

    public function testPostReturns201WhenTitleMeetsMinLength(): void
    {
        $response = $this->executeApiWriteRequestAs('POST', '/_api/articles', 1, [
            'title' => 'abc',
        ]);

        self::assertSame(201, $response->getStatusCode());
    }

    // ── regex validator ───────────────────────────────────────────────────────

    public function testPostReturns422WhenTitleFailsRegex(): void
    {
        $response = $this->executeApiWriteRequestAs('POST', '/_api/articles', 1, [
            'title' => 'Invalid!!',
        ]);
        $body = $this->decodeResponseBody($response);

        self::assertSame(422, $response->getStatusCode());
        $codes = array_column($body['violations'], 'code');
        self::assertContains('REGEX', $codes);
    }

    public function testPostReturns201WhenTitleMatchesRegex(): void
    {
        $response = $this->executeApiWriteRequestAs('POST', '/_api/articles', 1, [
            'title' => 'Valid Title',
        ]);

        self::assertSame(201, $response->getStatusCode());
    }
}
