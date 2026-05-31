<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api\Language;

use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;
use TYPO3\TestingFramework\Core\Functional\Framework\Frontend\InternalRequest;

/**
 * Enforces that single-language sites keep existing API response behavior.
 */
final class SingleLanguageSiteRegressionTest extends ApiFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/sys_categories.csv');
    }

    /** Requests without locale headers return the expected English payload. */
    public function testRequestWithoutLocaleHeaderReturnsExpectedPayload(): void
    {
        $response = $this->executeApiRequest('/_api/sys-categories');
        $body = $this->decodeResponseBody($response);
        $titles = array_column($body['hydra:member'], 'title');
        sort($titles);

        self::assertCount(3, $body['hydra:member']);
        self::assertSame(['API', 'PHP', 'TYPO3'], $titles);
    }

    /** Explicit X-Locale zero returns the same decoded payload. */
    public function testRequestWithExplicitLocaleZeroHeaderReturnsByteIdenticalPayload(): void
    {
        $firstResponse = $this->executeApiRequest('/_api/sys-categories');
        $secondResponse = $this->executeFrontendSubRequest(
            (new InternalRequest('http://localhost/_api/sys-categories'))->withAddedHeader('X-Locale', '0'),
        );
        $firstBody = $this->decodeResponseBody($firstResponse);
        $secondBody = $this->decodeResponseBody($secondResponse);

        self::assertSame($firstBody, $secondBody);
    }

    /** Single-language payload members are identical with and without X-Locale. */
    public function testSingleLanguageSiteHasNoVaryHeaderImpact(): void
    {
        $firstResponse = $this->executeApiRequest('/_api/sys-categories');
        $secondResponse = $this->executeFrontendSubRequest(
            (new InternalRequest('http://localhost/_api/sys-categories'))->withAddedHeader('X-Locale', '0'),
        );
        $firstBody = $this->decodeResponseBody($firstResponse);
        $secondBody = $this->decodeResponseBody($secondResponse);

        self::assertSame($firstBody['hydra:member'], $secondBody['hydra:member']);
    }
}
