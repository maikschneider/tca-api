<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Unit\Serializer\Processing;

use MaikSchneider\TcaApi\Serializer\Processing\ProcessorGuard;
use MaikSchneider\TcaApi\Serializer\Processing\TypoLinkProcessor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Site\Entity\Site;

final class ProcessorGuardTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_REQUEST']);
        parent::tearDown();
    }

    #[Test]
    public function returnsTheProcessorResultWhenNothingThrows(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('error');

        $guard = new ProcessorGuard($logger);

        self::assertSame(
            'processed',
            $guard->run(static fn () => 'processed', TypoLinkProcessor::class, 'tt_content', 'header', 7),
        );
    }

    #[Test]
    public function degradesToNullWhenTheProcessorThrows(): void
    {
        $guard = new ProcessorGuard($this->createMock(LoggerInterface::class));

        self::assertNull(
            $guard->run(
                static fn () => throw new \RuntimeException('boom'),
                TypoLinkProcessor::class,
                'tt_content',
                'header',
                7,
            ),
        );
    }

    #[Test]
    public function catchesTypeErrorNotJustException(): void
    {
        $guard = new ProcessorGuard($this->createMock(LoggerInterface::class));

        // The reported bug surfaced as a TypeError, which is an Error, not an Exception.
        self::assertNull(
            $guard->run(
                static fn () => throw new \TypeError('wrong type'),
                TypoLinkProcessor::class,
                'tt_content',
                'header',
                7,
            ),
        );
    }

    #[Test]
    public function logsTheFailureWithLocatingContext(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('error')
            ->with(
                'TCA API column processing failed',
                self::callback(static function (array $context): bool {
                    self::assertSame('tt_content', $context['table']);
                    self::assertSame(7, $context['uid']);
                    self::assertSame('header', $context['column']);
                    self::assertSame(TypoLinkProcessor::class, $context['processor']);
                    self::assertSame(\RuntimeException::class, $context['exception']);
                    self::assertSame('boom', $context['message']);
                    return true;
                }),
            );

        (new ProcessorGuard($logger))->run(
            static fn () => throw new \RuntimeException('boom'),
            TypoLinkProcessor::class,
            'tt_content',
            'header',
            7,
        );
    }

    #[Test]
    public function rethrowsWhenDebugModeIsEnabled(): void
    {
        $GLOBALS['TYPO3_REQUEST'] = (new ServerRequest('https://example.com/'))
            ->withAttribute('site', $this->siteWithDebugMode(true));

        $guard = new ProcessorGuard($this->createMock(LoggerInterface::class));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('boom');

        $guard->run(
            static fn () => throw new \RuntimeException('boom'),
            TypoLinkProcessor::class,
            'tt_content',
            'header',
            7,
        );
    }

    #[Test]
    public function degradesWhenDebugModeIsDisabled(): void
    {
        $GLOBALS['TYPO3_REQUEST'] = (new ServerRequest('https://example.com/'))
            ->withAttribute('site', $this->siteWithDebugMode(false));

        $guard = new ProcessorGuard($this->createMock(LoggerInterface::class));

        self::assertNull(
            $guard->run(
                static fn () => throw new \RuntimeException('boom'),
                TypoLinkProcessor::class,
                'tt_content',
                'header',
                7,
            ),
        );
    }

    #[Test]
    public function degradesWhenThereIsNoRequestAtAll(): void
    {
        unset($GLOBALS['TYPO3_REQUEST']);

        $guard = new ProcessorGuard($this->createMock(LoggerInterface::class));

        self::assertNull(
            $guard->run(
                static fn () => throw new \RuntimeException('boom'),
                TypoLinkProcessor::class,
                'tt_content',
                'header',
                7,
            ),
        );
    }

    private function siteWithDebugMode(bool $enabled): Site
    {
        return new Site('test', 1, [
            'base'      => 'https://example.com/',
            'languages' => [],
            'settings'  => ['tca_api' => ['debugMode' => $enabled]],
        ]);
    }
}
