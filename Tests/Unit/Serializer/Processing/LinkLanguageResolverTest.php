<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Unit\Serializer\Processing;

use MaikSchneider\TcaApi\Serializer\Processing\LinkLanguageResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;

final class LinkLanguageResolverTest extends TestCase
{
    private Site $source;
    private Site $target;

    protected function setUp(): void
    {
        parent::setUp();
        $this->source = new Site('source', 1, ['base' => 'https://source.example/', 'languages' => [
            ['languageId' => 0, 'base' => '/', 'locale' => 'en_US'],
            ['languageId' => 1, 'base' => '/de/', 'locale' => 'de_DE', 'fallbacks' => '0'],
            ['languageId' => 2, 'base' => '/de-ch/', 'locale' => 'de_CH', 'fallbacks' => '1,0'],
            ['languageId' => 3, 'base' => '/fr/', 'locale' => 'fr_FR'],
        ]]);
        $this->target = new Site('target', 2, ['base' => 'https://target.example/', 'languages' => [
            ['languageId' => 0, 'base' => '/', 'locale' => 'en_US'],
            ['languageId' => 1, 'base' => '/de/', 'locale' => 'de_DE'],
        ]]);
    }

    private function request(int $languageId): ServerRequest
    {
        return (new ServerRequest('https://source.example/'))
            ->withAttribute('tca_api.language', $this->source->getLanguageById($languageId));
    }

    #[Test]
    public function withoutRequestLanguageReturnsNull(): void
    {
        $resolver = new LinkLanguageResolver(self::createStub(ConnectionPool::class));

        self::assertNull($resolver->resolve($this->target, null));
        self::assertNull($resolver->resolve($this->target, new ServerRequest('https://source.example/')));
    }

    #[Test]
    public function returnsTargetLanguageWithTheSameId(): void
    {
        $language = (new LinkLanguageResolver(self::createStub(ConnectionPool::class)))->resolve($this->target, $this->request(1));

        self::assertSame(1, $language?->getLanguageId());
        self::assertSame('https://target.example/de/', (string)$language->getBase());
    }

    #[Test]
    public function returnsFirstFallbackTheTargetHas(): void
    {
        self::assertSame(1, (new LinkLanguageResolver(self::createStub(ConnectionPool::class)))->resolve($this->target, $this->request(2))?->getLanguageId());
    }

    #[Test]
    public function returnsTargetDefaultWhenNoFallbackMatches(): void
    {
        self::assertSame(0, (new LinkLanguageResolver(self::createStub(ConnectionPool::class)))->resolve($this->target, $this->request(3))?->getLanguageId());
    }

    #[Test]
    public function apiLanguageWinsOverSiteResolverLanguage(): void
    {
        $request = $this->request(1)->withAttribute('language', $this->source->getLanguageById(0));

        self::assertSame(1, (new LinkLanguageResolver(self::createStub(ConnectionPool::class)))->requestLanguage($request)?->getLanguageId());
    }

    #[Test]
    public function fallsBackToSiteResolverLanguage(): void
    {
        $request = (new ServerRequest('https://source.example/'))
            ->withAttribute('language', $this->source->getLanguageById(1));

        self::assertInstanceOf(SiteLanguage::class, (new LinkLanguageResolver(self::createStub(ConnectionPool::class)))->requestLanguage($request));
        self::assertSame(1, (new LinkLanguageResolver(self::createStub(ConnectionPool::class)))->requestLanguage($request)->getLanguageId());
    }
}
