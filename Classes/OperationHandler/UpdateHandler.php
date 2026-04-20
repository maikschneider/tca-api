<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\OperationHandler;

use MaikSchneider\TcaApi\DataAccess\DataRepository;
use MaikSchneider\TcaApi\DataAccess\DataWriteService;
use MaikSchneider\TcaApi\DataAccess\RelationInputResolver;
use MaikSchneider\TcaApi\Event\AfterWriteEvent;
use MaikSchneider\TcaApi\Event\BeforeWriteEvent;
use MaikSchneider\TcaApi\FileUpload\FileOwnershipService;
use MaikSchneider\TcaApi\Serializer\HydraResponseBuilder;
use MaikSchneider\TcaApi\Serializer\ResourceSerializer;
use MaikSchneider\TcaApi\Validation\FieldValidator;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;

#[Autoconfigure(public: true)]
class UpdateHandler implements OperationHandlerInterface
{
    use ColumnFilterTrait;

    public function __construct(
        private readonly DataWriteService $writeService,
        private readonly DataRepository $dataRepository,
        private readonly ResourceSerializer $serializer,
        private readonly HydraResponseBuilder $hydraResponseBuilder,
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly FieldValidator $fieldValidator,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly RelationInputResolver $relationResolver,
        private readonly FileOwnershipService $fileOwnershipService,
    ) {
    }

    public function supports(ServerRequestInterface $request, string $operation, array $config): bool
    {
        return $operation === 'update';
    }

    public function handle(ServerRequestInterface $request, array $config): ResponseInterface
    {
        $uid     = (int)$request->getAttribute('tca_api.uid');
        $partial = (bool)$request->getAttribute('tca_api.partial', false);

        return $this->doHandle($request, $config, $uid, $partial);
    }

    public function getPriority(): int
    {
        return 10;
    }

    private function doHandle(ServerRequestInterface $request, array $config, int $uid, bool $partial = false): ResponseInterface
    {
        $table = $config['general']['table'];

        if ($this->dataRepository->findById($table, $uid, $config) === null) {
            return $this->responseFactory->createResponse(404)
                ->withHeader('Content-Type', 'application/ld+json');
        }

        $raw = (string)$request->getBody();
        try {
            $body = $raw !== '' ? (json_decode($raw, true, 512, JSON_THROW_ON_ERROR) ?? []) : [];
        } catch (\JsonException) {
            return $this->hydraResponseBuilder->buildError(400, 'Request body is not valid JSON.', 'Bad Request');
        }

        $violations = $this->fieldValidator->validate($body, $config, $partial);
        if ($violations !== []) {
            return $this->hydraResponseBuilder->buildValidationError($violations);
        }

        $pid = $config['general']['defaultPid'] ?? 1;

        // Resolve relation fields. Security + validation on nested child objects
        // is enforced inside resolve(); violations bubble up here.
        $resolved = $this->relationResolver->resolve($body, $table, $pid, $request);
        if ($resolved->violations !== []) {
            return $this->hydraResponseBuilder->buildValidationError($resolved->violations);
        }

        $data = $this->filterWritableColumns($resolved->scalarBody, $config);

        if ($config['ownership']['checkFileColumns'] ?? false) {
            $feUserUid = (int)($feUser?->user['uid'] ?? 0);
            $denied    = $this->fileOwnershipService->checkFileColumns($data, $table, $feUserUid);
            if ($denied !== []) {
                return $this->hydraResponseBuilder->buildError(
                    403,
                    'File ownership check failed for sys_file UIDs: ' . implode(', ', $denied),
                    'Forbidden',
                );
            }
        }

        $beforeEvent = new BeforeWriteEvent($table, 'update', $data);
        $this->eventDispatcher->dispatch($beforeEvent);
        $data = $beforeEvent->getData();

        // Single DataHandler call: parent update + any new related records.
        $dataMap = [$table => [$uid => $data]] + $resolved->extraDataMap;
        $this->writeService->processDataMap($dataMap);

        $this->eventDispatcher->dispatch(new AfterWriteEvent($table, 'update', $uid));

        $row       = $this->dataRepository->findById($table, $uid, $config);
        $apiPrefix = (string)$request->getAttribute('tca_api.api_prefix', '/_api');
        $baseUrl   = $apiPrefix . '/' . $config['general']['resourceName'];

        return $this->hydraResponseBuilder->buildItem(
            $this->serializer->serialize($row, $config, $baseUrl),
        );
    }
}
