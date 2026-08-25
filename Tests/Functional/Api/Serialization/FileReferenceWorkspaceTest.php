<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api\Serialization;

use MaikSchneider\TcaApi\Serializer\FileProcessing\FileProcessor;
use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;
use Psr\Http\Message\ResponseInterface;
use TYPO3\TestingFramework\Core\Functional\Framework\Frontend\InternalRequest;
use TYPO3\TestingFramework\Core\Functional\Framework\Frontend\InternalRequestContext;

/**
 * Guards the workspace overlay of preloaded file references.
 *
 * Resolving references per record went through ResourceFactory, which loads the
 * row via PageRepository::checkRecord() and therefore applies versionOL(). The
 * preload fetches the rows itself, so it has to apply the same overlay — without
 * it a workspace preview would serve the live reference metadata.
 *
 * Fixture data: article 2500 with one `downloads` reference, uid 200 titled
 * "Live Title", versioned in workspace 1 as uid 201 titled "Workspace Title".
 */
final class FileReferenceWorkspaceTest extends ApiFunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['workspaces'];

    private const CONFIG = [
        'general' => [
            'table'        => 'tx_myext_domain_model_article',
            'resourceName' => 'workspace-files',
            'resourceType' => 'WorkspaceFileArticle',
            'operations'   => ['list', 'show'],
        ],
        'columns' => [
            'title'     => ['groups' => ['list', 'show']],
            'downloads' => ['groups' => ['list', 'show'], 'processor' => FileProcessor::class],
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/sys_workspace.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/articles_workspace_files.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/sys_file_workspace.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/sys_file_reference_workspace.csv');

        $this->registerResource('workspace-files', self::CONFIG);
    }

    public function testLiveRequestReturnsTheLiveReferenceTitle(): void
    {
        $body = $this->decodeResponseBody($this->executeApiRequest('/_api/workspace-files/2500'));

        self::assertSame('Live Title', $body['downloads'][0]['metadata']['title']);
    }

    public function testWorkspacePreviewReturnsTheVersionedReferenceTitle(): void
    {
        $body = $this->decodeResponseBody($this->executeApiRequestInWorkspace('/_api/workspace-files/2500', 1));

        self::assertSame('Workspace Title', $body['downloads'][0]['metadata']['title']);
    }

    public function testWorkspacePreviewReturnsTheVersionedReferenceDescription(): void
    {
        $body = $this->decodeResponseBody($this->executeApiRequestInWorkspace('/_api/workspace-files/2500', 1));

        self::assertSame('Workspace Description', $body['downloads'][0]['metadata']['description']);
    }

    /**
     * A collection goes through the preload, an item request through the same
     * code path — both have to agree with the workspace.
     */
    public function testWorkspacePreviewAppliesToCollectionsToo(): void
    {
        $body = $this->decodeResponseBody($this->executeApiRequestInWorkspace('/_api/workspace-files', 1));

        $member = $body['hydra:member'][0];
        self::assertSame(2500, $member['uid']);
        self::assertSame('Workspace Title', $member['downloads'][0]['metadata']['title']);
    }

    private function executeApiRequestInWorkspace(string $path, int $workspaceId): ResponseInterface
    {
        return $this->executeFrontendSubRequest(
            new InternalRequest('http://localhost' . $path),
            (new InternalRequestContext())->withBackendUserId(2)->withWorkspaceId($workspaceId),
        );
    }
}
