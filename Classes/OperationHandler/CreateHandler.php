<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\OperationHandler;

use MaikSchneider\TcaApi\DataAccess\DataRepository;
use MaikSchneider\TcaApi\DataAccess\DataWriteService;
use MaikSchneider\TcaApi\DataAccess\RelationInputResolver;
use MaikSchneider\TcaApi\Event\AfterWriteEvent;
use MaikSchneider\TcaApi\Event\BeforeWriteEvent;
use MaikSchneider\TcaApi\Serializer\HydraResponseBuilder;
use MaikSchneider\TcaApi\Serializer\ResourceSerializer;
use MaikSchneider\TcaApi\Validation\FieldValidator;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;

#[Autoconfigure(public: true)]
class CreateHandler implements OperationHandlerInterface
{
    use ColumnFilterTrait;

    public function __construct(
        private readonly DataWriteService $writeService,
        private readonly DataRepository $dataRepository,
        private readonly ResourceSerializer $serializer,
        private readonly HydraResponseBuilder $hydraResponseBuilder,
        private readonly FieldValidator $fieldValidator,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly RelationInputResolver $relationResolver,
    ) {
    }

    public function supports(ServerRequestInterface $request, string $operation, array $config): bool
    {
        return $operation === 'create';
    }

    public function handle(ServerRequestInterface $request, array $config): ResponseInterface
    {
        $raw  = (string)$request->getBody();
        $body = $raw !== '' ? (json_decode($raw, true, 512, JSON_THROW_ON_ERROR) ?? []) : [];

        $violations = $this->fieldValidator->validate($body, $config);
        if ($violations !== []) {
            return $this->hydraResponseBuilder->buildValidationError($violations);
        }

        $table        = $config['general']['table'];
        $pid          = $config['general']['defaultPid'] ?? 1;
        $feUser       = $request->getAttribute('frontend.user');
        $writableBody = $this->filterWritableColumns($body, $config);

        // Resolve relation fields only for writable columns: objects become NEW_xxx
        // placeholders; UIDs stay as-is. This prevents side effects for relation
        // input on non-writable columns that would later be filtered out.
        $resolved = $this->relationResolver->resolve($writableBody, $table, $pid, $feUser?->user);

        $data        = $this->filterWritableColumns($resolved->scalarBody, $config);
        $data['pid'] = $pid;

        if ($feUser !== null && !empty($feUser->user['uid'])) {
            $feUid       = (int)$feUser->user['uid'];
            $authColumn  = $config['ownership']['column'] ?? null;
            $trackColumn = $config['ownership']['setOnCreate'] ?? null;
            if ($authColumn !== null) {
                $data[$authColumn] = $feUid;
            }
            if ($trackColumn !== null && $trackColumn !== $authColumn) {
                $data[$trackColumn] = $feUid;
            }
        }

        $beforeEvent = new BeforeWriteEvent($table, 'create', $data);
        $this->eventDispatcher->dispatch($beforeEvent);
        $data = $beforeEvent->getData();

        // Single DataHandler call: parent + all related new records atomically.
        // NEW_xxx placeholders in $data (e.g. color_id, inline column) are resolved
        // by DataHandler using the records in $resolved->extraDataMap.
        $primaryKey = 'NEW_primary';
        $dataMap    = [$table => [$primaryKey => $data]] + $resolved->extraDataMap;
        $substMap   = $this->writeService->processDataMap($dataMap);
        $uid        = (int)($substMap[$primaryKey] ?? 0);

        $this->eventDispatcher->dispatch(new AfterWriteEvent($table, 'create', $uid));

        $row      = $this->dataRepository->findById($table, $uid, $config);
        $baseUrl  = '/_api/' . $config['general']['resourceName'];
        $location = $baseUrl . '/' . $uid;

        return $this->hydraResponseBuilder->buildItem(
            $this->serializer->serialize($row, $config, $baseUrl),
        )->withStatus(201)->withHeader('Location', $location);
    }

    public function getPriority(): int
    {
        return 10;
    }
}
