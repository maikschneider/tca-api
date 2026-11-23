<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApiTestOpenApiDocument;

use MaikSchneider\TcaApi\Event\ModifyOpenApiDocumentEvent;
use TYPO3\CMS\Core\Attribute\AsEventListener;

/**
 * Adds a `DocumentNote` schema and, only if the document describes colors, a
 * `note` property on `ColorRead`. Keeps every event it receives for assertions.
 */
#[AsEventListener('test-openapi-document/add-note')]
final class AddDocumentNote
{
    /** @var list<ModifyOpenApiDocumentEvent> */
    public static array $events = [];

    public function __invoke(ModifyOpenApiDocumentEvent $event): void
    {
        self::$events[] = clone $event;

        $document = $event->getDocument();
        $document['components']['schemas']['DocumentNote'] = [
            'type'       => 'object',
            'properties' => ['text' => ['type' => 'string']],
        ];
        if (isset($document['components']['schemas']['ColorRead']['properties'])) {
            $document['components']['schemas']['ColorRead']['properties']['note'] = ['$ref' => '#/components/schemas/DocumentNote'];
        }
        $event->setDocument($document);
    }
}
