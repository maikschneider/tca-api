<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\DataAccess;

use MaikSchneider\TcaApi\Configuration\ApiDefinition;
use MaikSchneider\TcaApi\Configuration\ColumnDefinition;
use MaikSchneider\TcaApi\Utility\TcaColumnDiscovery;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\FrontendRestrictionContainer;
use TYPO3\CMS\Core\Database\Query\Restriction\RootLevelRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\WorkspaceRestriction;
use TYPO3\CMS\Core\Http\ApplicationType;
use TYPO3\CMS\Core\Resource\Event\EnrichFileMetaDataEvent;
use TYPO3\CMS\Core\Resource\Exception\ResourceDoesNotExistException;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Schema\Field\FileFieldType;
use TYPO3\CMS\Core\Schema\TcaSchema;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Resolves the file references of a whole page of records in a fixed number of queries.
 *
 * FileRepository::findByRelation() works per record, so a collection of N records
 * with a file column costs N lookups of sys_file_reference, sys_file and
 * sys_file_metadata each. This preloader issues one query per table instead and
 * primes the ResourceFactory runtime caches, so building the FileReference objects
 * afterwards touches the database no further.
 *
 * A column is resolved once no matter how many properties are derived from it —
 * a file column and a virtual property sourcing the same column share the
 * references. Only the processing of those references stays per property.
 */
final class FileReferencePreloader
{
    /**
     * Mirrors the column list TYPO3 uses to build a File object
     * (\TYPO3\CMS\Core\Resource\Index\FileIndexRepository::FIELDS). Selecting a
     * superset would let sys_file columns shadow same-named metadata columns in
     * File::getProperties().
     */
    private const FILE_FIELDS = [
        'uid', 'pid', 'missing', 'type', 'storage', 'identifier', 'identifier_hash', 'extension',
        'mime_type', 'name', 'sha1', 'size', 'creation_date', 'modification_date', 'folder_hash',
    ];

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly ResourceFactory $resourceFactory,
        private readonly TcaSchemaFactory $schemaFactory,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly Context $context,
    ) {
    }

    /**
     * @param list<int> $parentUids
     */
    public function preload(ApiDefinition $config, array $parentUids): PreloadedFileReferences
    {
        $empty = new PreloadedFileReferences([], []);

        // Outside the frontend, FileRepository resolves references through the
        // RelationHandler with workspace handling this query cannot reproduce.
        if ($parentUids === [] || !$this->isFrontend()) {
            return $empty;
        }

        $columns = $this->resolveFileColumns($config, $this->schemaFactory->get($config->table));
        if ($columns === []) {
            return $empty;
        }

        $referenceRows = $this->fetchReferenceRows($config->table, $columns, $parentUids);

        $this->warmFileObjects(array_map(static fn (array $row) => (int)$row['uid_local'], $referenceRows));

        $references = array_fill_keys($columns, []);
        foreach ($referenceRows as $row) {
            try {
                $references[$row['fieldname']][(int)$row['uid_foreign']][] =
                    $this->resourceFactory->getFileReferenceObject((int)$row['uid'], $row);
            } catch (ResourceDoesNotExistException) {
                // Same as FileRepository::findByRelation(): drop references whose file is gone.
            }
        }

        return new PreloadedFileReferences(array_fill_keys($parentUids, true), $references);
    }

    /**
     * File columns the serializer will read for this resource — the readable file
     * columns plus every column a virtual property sources from.
     *
     * @return list<string>
     */
    private function resolveFileColumns(ApiDefinition $config, TcaSchema $schema): array
    {
        $candidates = $config->isExplicitMode
            ? array_keys(array_filter($config->columns, static fn (ColumnDefinition $def) => $def->isReadable()))
            : TcaColumnDiscovery::getExposableColumnNames($config->table);

        foreach ($config->virtualProperties as $virtualProperty) {
            if ($virtualProperty->column !== null && (!$config->isExplicitMode || $virtualProperty->isReadable())) {
                $candidates[] = $virtualProperty->column;
            }
        }

        $columns = [];
        foreach (array_unique($candidates) as $column) {
            if ($schema->hasField($column) && $schema->getField($column) instanceof FileFieldType) {
                $columns[] = $column;
            }
        }

        return $columns;
    }

    /**
     * @param list<string> $columns
     * @param list<int>    $parentUids
     * @return list<array<string, mixed>>
     */
    private function fetchReferenceRows(string $table, array $columns, array $parentUids): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('sys_file_reference');
        $qb->setRestrictions(GeneralUtility::makeInstance(FrontendRestrictionContainer::class));

        return $qb->select('*')
            ->from('sys_file_reference')
            ->where(
                $qb->expr()->eq('tablenames', $qb->createNamedParameter($table)),
                $qb->expr()->in('fieldname', $qb->createNamedParameter($columns, Connection::PARAM_STR_ARRAY)),
                $qb->expr()->in('uid_foreign', $qb->createNamedParameter($parentUids, Connection::PARAM_INT_ARRAY)),
            )
            ->orderBy('sorting_foreign')
            ->addOrderBy('uid')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * Puts every referenced file — and its metadata — into the ResourceFactory
     * runtime cache, so constructing the FileReference objects issues no queries.
     *
     * @param list<int> $fileUids
     */
    private function warmFileObjects(array $fileUids): void
    {
        $fileUids = array_values(array_unique(array_filter($fileUids, static fn (int $uid) => $uid > 0)));
        if ($fileUids === []) {
            return;
        }

        $metaData = $this->fetchMetaDataRows($fileUids);

        foreach ($this->fetchFileRows($fileUids) as $fileUid => $fileRow) {
            try {
                $this->resourceFactory->getFileObject($fileUid, $fileRow)
                    ->getMetaData()
                    ->add($metaData[$fileUid] ?? []);
            } catch (\Exception) {
                // Warming is an optimisation; leave the file to the lazy path.
            }
        }
    }

    /**
     * @param list<int> $fileUids
     * @return array<int, array<string, mixed>>
     */
    private function fetchFileRows(array $fileUids): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('sys_file');

        $rows = $qb->select(...self::FILE_FIELDS)
            ->from('sys_file')
            ->where(
                $qb->expr()->in('uid', $qb->createNamedParameter($fileUids, Connection::PARAM_INT_ARRAY)),
            )
            ->executeQuery()
            ->fetchAllAssociative();

        $byUid = [];
        foreach ($rows as $row) {
            $byUid[(int)$row['uid']] = $row;
        }

        return $byUid;
    }

    /**
     * Mirrors MetaDataRepository::findByFileUid() for a set of files, including the
     * enrichment event that carries the frontend language overlay.
     *
     * @param list<int> $fileUids
     * @return array<int, array<string, mixed>>
     */
    private function fetchMetaDataRows(array $fileUids): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('sys_file_metadata');
        $qb->getRestrictions()
            ->add(GeneralUtility::makeInstance(RootLevelRestriction::class))
            ->add(GeneralUtility::makeInstance(WorkspaceRestriction::class, $this->context->getAspect('workspace')->getId()));

        $rows = $qb->select('*')
            ->from('sys_file_metadata')
            ->where(
                $qb->expr()->in('file', $qb->createNamedParameter($fileUids, Connection::PARAM_INT_ARRAY)),
                $qb->expr()->in('sys_language_uid', $qb->createNamedParameter([0, -1], Connection::PARAM_INT_ARRAY)),
            )
            ->orderBy('uid', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        $byFile = [];
        foreach ($rows as $row) {
            $fileUid = (int)$row['file'];

            // findByFileUid() takes the lowest uid per file; the ordering above makes that the first hit.
            if (isset($byFile[$fileUid])) {
                continue;
            }

            $event = new EnrichFileMetaDataEvent($fileUid, (int)$row['uid'], $row);
            $this->eventDispatcher->dispatch($event);
            $byFile[$fileUid] = $event->getRecord();
        }

        return $byFile;
    }

    private function isFrontend(): bool
    {
        $request = $GLOBALS['TYPO3_REQUEST'] ?? null;

        return $request instanceof ServerRequestInterface && ApplicationType::fromRequest($request)->isFrontend();
    }
}
