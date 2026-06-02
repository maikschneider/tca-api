<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api\Write;

use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * End-to-end tests proving that TCA-derived validators are enforced in default-mode
 * write resources (no explicit columns in the API config).
 *
 * Uses the colors-validate-default resource (Configuration/TcaApi/ColorsDefaultWrite.php),
 * which has no explicit columns. The TCA for tx_myext_domain_model_color declares:
 *   - name:  input, max=255 → derives maxLength:255
 *   - hex:   input, max=7   → derives maxLength:7
 *
 * Before the fix, every POST succeeded regardless of field length because
 * FieldValidator::validate() iterated $config->columns (empty in default mode).
 */
final class DefaultModeValidationTest extends ApiFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/colors.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/fe_users.csv');
    }

    // ── maxLength enforcement — default mode ──────────────────────────────────

    #[Test]
    public function postWithNameExceedingTcaMaxReturns422(): void
    {
        $response = $this->executeApiWriteRequestAs('POST', '/_api/colors-validate-default', 1, [
            'name' => str_repeat('a', 256),
        ]);
        $body = $this->decodeResponseBody($response);

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('violations', $body);
        $codes = array_column($body['violations'], 'code');
        self::assertContains('MAX_LENGTH', $codes);
        $paths = array_column($body['violations'], 'propertyPath');
        self::assertContains('name', $paths);
    }

    #[Test]
    public function postWithHexExceedingTcaMaxReturns422(): void
    {
        $response = $this->executeApiWriteRequestAs('POST', '/_api/colors-validate-default', 1, [
            'name' => 'Green',
            'hex'  => '#AABBCCDD', // 9 chars — exceeds max=7
        ]);
        $body = $this->decodeResponseBody($response);

        self::assertSame(422, $response->getStatusCode());
        $codes = array_column($body['violations'], 'code');
        self::assertContains('MAX_LENGTH', $codes);
        $paths = array_column($body['violations'], 'propertyPath');
        self::assertContains('hex', $paths);
    }

    #[Test]
    public function postWithValidFieldsReturns201(): void
    {
        $response = $this->executeApiWriteRequestAs('POST', '/_api/colors-validate-default', 1, [
            'name' => 'Valid Name',
            'hex'  => '#AABBCC',
        ]);

        self::assertSame(201, $response->getStatusCode());
    }

    // ── PATCH partial update — only supplied fields validated ─────────────────

    #[Test]
    public function patchWithInvalidNameReturns422(): void
    {
        $response = $this->executeApiWriteRequestAs('PATCH', '/_api/colors-validate-default/1', 1, [
            'name' => str_repeat('b', 256),
        ]);

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function patchWithValidNameAndAbsentHexReturns200(): void
    {
        // PATCH with only 'name' — 'hex' absent so its maxLength:7 must NOT fire.
        $response = $this->executeApiWriteRequestAs('PATCH', '/_api/colors-validate-default/1', 1, [
            'name' => 'Updated',
        ]);

        self::assertSame(200, $response->getStatusCode());
    }
}
