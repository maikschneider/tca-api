<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Unit\OpenApi;

use MaikSchneider\TcaApi\Configuration\ApiDefinition;
use MaikSchneider\TcaApi\Dispatcher\RequestContext;
use MaikSchneider\TcaApi\Event\ModifyOpenApiDocumentEvent;
use MaikSchneider\TcaApi\OpenApi\OpenApiBuilder;
use MaikSchneider\TcaApi\OpenApi\OpenApiOperationBuilder;
use MaikSchneider\TcaApi\OpenApi\OpenApiPathsBuilder;
use MaikSchneider\TcaApi\OpenApi\OpenApiSchemasBuilder;
use MaikSchneider\TcaApi\OpenApi\OpenApiSecurityBuilder;
use MaikSchneider\TcaApi\Registry\ApiRegistry;
use MaikSchneider\TcaApi\Tca\SqlColumnTypes;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Settings\Settings;
use TYPO3\CMS\Core\Site\Entity\SiteSettings;

final class OpenApiBuilderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        (new ApiRegistry())->reset();
    }

    protected function tearDown(): void
    {
        (new ApiRegistry())->reset();
        parent::tearDown();
    }

    #[Test]
    public function dispatchesTheFinishedDocumentOnceAndReturnsTheListenersDocument(): void
    {
        $context    = $this->context();
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::once())
            ->method('dispatch')
            ->willReturnCallback(static function (object $event) use ($context): object {
                self::assertInstanceOf(ModifyOpenApiDocumentEvent::class, $event);
                self::assertSame('3.1.0', $event->getDocument()['openapi']);
                self::assertSame([], $event->getResources());
                self::assertSame($context, $event->getContext());
                $event->setDocument(['openapi' => '3.1.0', 'x-replaced' => true]);
                return $event;
            });

        $document = $this->builder($dispatcher)->build($context);

        self::assertSame(['openapi' => '3.1.0', 'x-replaced' => true], $document);
    }

    #[Test]
    public function buildsWithoutAnEventDispatcher(): void
    {
        $builder = new OpenApiBuilder(
            new ApiRegistry(),
            $this->pathsBuilder(),
            new OpenApiSchemasBuilder(self::createStub(SqlColumnTypes::class)),
            new OpenApiSecurityBuilder(self::createStub(EventDispatcherInterface::class)),
        );

        $document = $builder->build($this->context());

        self::assertSame('3.1.0', $document['openapi']);
        self::assertSame([], $document['paths']);
    }

    #[Test]
    public function aListenerReceivesOnlyTheResourcesOfTheSite(): void
    {
        $registry = new ApiRegistry();
        foreach (['articles', 'colors'] as $name) {
            $registry->register($name, ApiDefinition::fromArray([
                'general' => [
                    'table'        => 'tx_test_' . $name,
                    'resourceName' => $name,
                    'resourceType' => ucfirst($name),
                    'operations'   => ['list'],
                ],
            ]));
        }
        $seen       = null;
        $dispatcher = self::createStub(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willReturnCallback(static function (object $event) use (&$seen): object {
            $seen = $event;
            return $event;
        });

        $this->builder($dispatcher)->build($this->context(['tca_api.allowedResources' => 'articles']));

        self::assertInstanceOf(ModifyOpenApiDocumentEvent::class, $seen);
        self::assertSame(['articles'], array_keys($seen->getResources()));
        self::assertSame(['/_api/articles'], array_keys($seen->getDocument()['paths']));
    }

    private function builder(EventDispatcherInterface $dispatcher): OpenApiBuilder
    {
        return new OpenApiBuilder(
            new ApiRegistry(),
            $this->pathsBuilder(),
            new OpenApiSchemasBuilder(self::createStub(SqlColumnTypes::class)),
            new OpenApiSecurityBuilder(self::createStub(EventDispatcherInterface::class)),
            $dispatcher,
        );
    }

    private function pathsBuilder(): OpenApiPathsBuilder
    {
        return new OpenApiPathsBuilder(
            new OpenApiOperationBuilder(),
            new OpenApiSecurityBuilder(self::createStub(EventDispatcherInterface::class)),
        );
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function context(array $settings = []): RequestContext
    {
        return new RequestContext(new SiteSettings(new Settings($settings), [], []), new ServerRequest('https://example.org/'));
    }
}
