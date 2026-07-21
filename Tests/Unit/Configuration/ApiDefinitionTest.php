<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Unit\Configuration;

use MaikSchneider\TcaApi\Configuration\ApiDefinition;
use MaikSchneider\TcaApi\Enum\AccessRole;
use MaikSchneider\TcaApi\Enum\WriteMode;
use MaikSchneider\TcaApi\Filter\ExactFilter;
use MaikSchneider\TcaApi\Filter\FilterDefinition;
use MaikSchneider\TcaApi\Filter\SearchFilter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ApiDefinitionTest extends TestCase
{
    /**
     * Returns a minimal valid raw config array that can be modified per test.
     */
    private static function minimalConfig(): array
    {
        return [
            'general' => [
                'table' => 'tx_test',
                'resourceName' => 'tests',
                'resourceType' => 'Test',
            ],
        ];
    }

    // ── Happy-path tests ────────────────────────────────────────────────

    #[Test]
    public function minimalConfigCreatesDefinition(): void
    {
        $def = ApiDefinition::fromArray(self::minimalConfig());

        self::assertSame('tx_test', $def->table);
        self::assertSame('tests', $def->resourceName);
        self::assertSame('Test', $def->resourceType);
        self::assertSame(['list', 'show'], $def->operations);
        self::assertNull($def->itemsPerPage);
        self::assertNull($def->maxItemsPerPage);
        self::assertNull($def->type);
        self::assertNull($def->storagePid);
        self::assertSame([], $def->columns);
        self::assertSame([], $def->security);
        self::assertSame([], $def->filters);
        self::assertSame([], $def->allowedOrder);
        self::assertSame([], $def->defaultOrder);
        self::assertNull($def->ownershipColumn);
        self::assertNull($def->ownershipSetOnCreate);
        self::assertTrue($def->ownershipBeAdminBypass);
        self::assertSame([], $def->virtualProperties);
        self::assertFalse($def->isExplicitMode);
        self::assertSame(WriteMode::ACTING_USER, $def->writeMode);
        self::assertNull($def->readStoragePids);
    }

    // ── readStoragePids resolution ──────────────────────────────────────

    #[Test]
    public function readStoragePidsDefaultsToWriteTargetWhenStoragePidSet(): void
    {
        $cfg = self::minimalConfig();
        $cfg['general']['storagePid'] = 42;
        $def = ApiDefinition::fromArray($cfg);

        self::assertSame(42, $def->storagePid);
        self::assertSame([42], $def->readStoragePids);
    }

    #[Test]
    public function readStoragePidsStarReadsAllWhileKeepingWriteTarget(): void
    {
        $cfg = self::minimalConfig();
        $cfg['general']['storagePid']      = 42;
        $cfg['general']['readStoragePids'] = '*';
        $def = ApiDefinition::fromArray($cfg);

        self::assertSame(42, $def->storagePid);
        self::assertNull($def->readStoragePids);
    }

    #[Test]
    public function readStoragePidsAcceptsArray(): void
    {
        $cfg = self::minimalConfig();
        $cfg['general']['storagePid']      = 42;
        $cfg['general']['readStoragePids'] = [42, 17, 5];
        $def = ApiDefinition::fromArray($cfg);

        self::assertSame([42, 17, 5], $def->readStoragePids);
    }

    #[Test]
    public function readStoragePidsAcceptsCsvString(): void
    {
        $cfg = self::minimalConfig();
        $cfg['general']['readStoragePids'] = '42, 17 ,5';
        $def = ApiDefinition::fromArray($cfg);

        self::assertSame([42, 17, 5], $def->readStoragePids);
    }

    #[Test]
    public function readStoragePidsDeduplicates(): void
    {
        $cfg = self::minimalConfig();
        $cfg['general']['readStoragePids'] = [42, 42, 17];
        $def = ApiDefinition::fromArray($cfg);

        self::assertSame([42, 17], $def->readStoragePids);
    }

    #[Test]
    public function readStoragePidsThrowsWhenEmpty(): void
    {
        $cfg = self::minimalConfig();
        $cfg['general']['readStoragePids'] = [];

        $this->expectException(\InvalidArgumentException::class);
        ApiDefinition::fromArray($cfg);
    }

    #[Test]
    public function readStoragePidsThrowsWhenNoIntegers(): void
    {
        $cfg = self::minimalConfig();
        $cfg['general']['readStoragePids'] = ['foo', 'bar'];

        $this->expectException(\InvalidArgumentException::class);
        ApiDefinition::fromArray($cfg);
    }

    #[Test]
    public function fullConfigCreatesDefinition(): void
    {
        $raw = [
            'general' => [
                'table' => 'tx_news',
                'resourceName' => 'news',
                'resourceType' => 'News',
                'operations' => ['list', 'show', 'create', 'update', 'delete'],
                'itemsPerPage' => 20,
                'maxItemsPerPage' => 100,
                'writeMode' => 'system_admin',
            ],
            'columns' => [
                'title' => [
                    'groups' => ['list', 'show', 'create', 'update'],
                    'required' => true,
                    'type' => 'string',
                    'validators' => [['type' => 'maxLength', 'max' => 255]],
                ],
            ],
            'security' => [
                'list' => AccessRole::PUBLIC,
                'show' => AccessRole::PUBLIC,
                'create' => AccessRole::FE_USER,
                'update' => AccessRole::FE_USER,
                'delete' => AccessRole::BE_ADMIN,
            ],
            'filters' => [
                'title' => 'MaikSchneider\\TcaApi\\Filter\\ExactFilter',
            ],
            'order' => [
                'allowed' => ['title', 'uid'],
                'default' => ['uid' => 'asc'],
            ],
            'ownership' => [
                'column' => 'fe_user',
                'setOnCreate' => 'uid',
                'beAdminBypass' => false,
            ],
        ];

        $def = ApiDefinition::fromArray($raw);

        self::assertSame('tx_news', $def->table);
        self::assertCount(5, $def->operations);
        self::assertSame(20, $def->itemsPerPage);
        self::assertSame(100, $def->maxItemsPerPage);
        self::assertSame(WriteMode::SYSTEM_ADMIN, $def->writeMode);
        self::assertArrayHasKey('title', $def->columns);
        self::assertTrue($def->isExplicitMode);
        self::assertSame('fe_user', $def->ownershipColumn);
        self::assertSame('uid', $def->ownershipSetOnCreate);
        self::assertFalse($def->ownershipBeAdminBypass);
        self::assertSame(['title', 'uid'], $def->allowedOrder);
        self::assertSame(['uid' => 'asc'], $def->defaultOrder);
    }

    // ── Missing required general fields ─────────────────────────────────

    #[Test]
    public function missingTableThrows(): void
    {
        $cfg = self::minimalConfig();
        unset($cfg['general']['table']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('general.table');
        ApiDefinition::fromArray($cfg);
    }

    #[Test]
    public function missingResourceNameThrows(): void
    {
        $cfg = self::minimalConfig();
        unset($cfg['general']['resourceName']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('general.resourceName');
        ApiDefinition::fromArray($cfg);
    }

    #[Test]
    public function missingResourceTypeThrows(): void
    {
        $cfg = self::minimalConfig();
        unset($cfg['general']['resourceType']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('general.resourceType');
        ApiDefinition::fromArray($cfg);
    }

    #[Test]
    public function omittedOperationsDefaultToListAndShow(): void
    {
        $def = ApiDefinition::fromArray(self::minimalConfig());

        self::assertSame(['list', 'show'], $def->operations);
    }

    // ── securityRole() defaults ─────────────────────────────────────────

    #[Test]
    public function securityRoleForListDefaultsToPublic(): void
    {
        $def = ApiDefinition::fromArray(self::minimalConfig());

        self::assertSame(AccessRole::PUBLIC, $def->securityRole('list'));
    }

    #[Test]
    public function securityRoleForShowDefaultsToPublic(): void
    {
        $cfg = self::minimalConfig();
        $cfg['general']['operations'] = ['show'];
        $def = ApiDefinition::fromArray($cfg);

        self::assertSame(AccessRole::PUBLIC, $def->securityRole('show'));
    }

    #[Test]
    public function securityRoleForCreateDefaultsToDisabled(): void
    {
        $cfg = self::minimalConfig();
        $cfg['general']['operations'] = ['create'];
        $def = ApiDefinition::fromArray($cfg);

        self::assertSame(AccessRole::DISABLED, $def->securityRole('create'));
    }

    #[Test]
    public function securityRoleForUpdateDefaultsToDisabled(): void
    {
        $cfg = self::minimalConfig();
        $cfg['general']['operations'] = ['update'];
        $def = ApiDefinition::fromArray($cfg);

        self::assertSame(AccessRole::DISABLED, $def->securityRole('update'));
    }

    #[Test]
    public function securityRoleForDeleteDefaultsToDisabled(): void
    {
        $cfg = self::minimalConfig();
        $cfg['general']['operations'] = ['delete'];
        $def = ApiDefinition::fromArray($cfg);

        self::assertSame(AccessRole::DISABLED, $def->securityRole('delete'));
    }

    #[Test]
    public function securityRoleReturnsExplicitlyConfiguredRole(): void
    {
        $cfg = self::minimalConfig();
        $cfg['general']['operations'] = ['create', 'list'];
        $cfg['security'] = [
            'create' => AccessRole::FE_USER,
            'list'   => AccessRole::BE_ADMIN,
        ];
        $def = ApiDefinition::fromArray($cfg);

        self::assertSame(AccessRole::FE_USER, $def->securityRole('create'));
        self::assertSame(AccessRole::BE_ADMIN, $def->securityRole('list'));
    }

    #[Test]
    public function securityRoleExplicitDisabledOverridesReadDefault(): void
    {
        $cfg = self::minimalConfig();
        $cfg['security'] = ['list' => AccessRole::DISABLED];
        $def = ApiDefinition::fromArray($cfg);

        self::assertSame(AccessRole::DISABLED, $def->securityRole('list'));
    }

    #[Test]
    public function securityRoleExplicitPublicOverridesWriteDefault(): void
    {
        $cfg = self::minimalConfig();
        $cfg['general']['operations'] = ['create'];
        $cfg['security'] = ['create' => AccessRole::PUBLIC];
        $def = ApiDefinition::fromArray($cfg);

        self::assertSame(AccessRole::PUBLIC, $def->securityRole('create'));
    }

    #[Test]
    public function operationsNotArrayThrows(): void
    {
        $cfg = self::minimalConfig();
        $cfg['general']['operations'] = 'list';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('general.operations must be an array');
        ApiDefinition::fromArray($cfg);
    }

    #[Test]
    public function invalidOperationValueThrows(): void
    {
        $cfg = self::minimalConfig();
        $cfg['general']['operations'] = ['list', 'purge'];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"purge"');
        ApiDefinition::fromArray($cfg);
    }

    // ── Invalid pagination ──────────────────────────────────────────────

    #[Test]
    public function negativeItemsPerPageThrows(): void
    {
        $cfg = self::minimalConfig();
        $cfg['general']['itemsPerPage'] = -5;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('itemsPerPage');
        ApiDefinition::fromArray($cfg);
    }

    #[Test]
    public function zeroMaxItemsPerPageThrows(): void
    {
        $cfg = self::minimalConfig();
        $cfg['general']['maxItemsPerPage'] = 0;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('maxItemsPerPage');
        ApiDefinition::fromArray($cfg);
    }

    // ── Invalid general.type ────────────────────────────────────────────

    #[Test]
    public function invalidGeneralTypeThrows(): void
    {
        $cfg = self::minimalConfig();
        $cfg['general']['type'] = 'foobar';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('general.type');
        ApiDefinition::fromArray($cfg);
    }

    #[Test]
    public function validGeneralTypeUserinfo(): void
    {
        $cfg = self::minimalConfig();
        $cfg['general']['type'] = 'userinfo';
        $def = ApiDefinition::fromArray($cfg);
        self::assertTrue($def->isUserInfo());
    }

    // ── Invalid security ────────────────────────────────────────────────

    #[Test]
    public function securityWithInvalidOperationKeyThrows(): void
    {
        $cfg = self::minimalConfig();
        $cfg['security'] = ['purge' => AccessRole::PUBLIC];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('security key "purge"');
        ApiDefinition::fromArray($cfg);
    }

    #[Test]
    public function securityWithPlainStringValueThrows(): void
    {
        $cfg = self::minimalConfig();
        $cfg['security'] = ['list' => 'PUBLIC'];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('security["list"]');
        ApiDefinition::fromArray($cfg);
    }

    #[Test]
    public function securityWithAccessRoleEnumIsAccepted(): void
    {
        $cfg = self::minimalConfig();
        $cfg['security'] = ['list' => AccessRole::FE_USER];
        $def = ApiDefinition::fromArray($cfg);
        self::assertSame(AccessRole::FE_USER, $def->security['list']);
    }

    #[Test]
    public function securityWithCallableTupleIsAccepted(): void
    {
        $cfg = self::minimalConfig();
        $cfg['security'] = ['update' => ['App\\MyChecker', 'check']];
        $def = ApiDefinition::fromArray($cfg);
        self::assertSame(['App\\MyChecker', 'check'], $def->security['update']);
    }

    #[Test]
    public function securityWithAccessRoleGroupTupleIsAccepted(): void
    {
        $cfg = self::minimalConfig();
        $cfg['security'] = ['show' => [AccessRole::FE_GROUP, [1, 2]]];
        $def = ApiDefinition::fromArray($cfg);
        self::assertSame([AccessRole::FE_GROUP, [1, 2]], $def->security['show']);
    }

    // ── Invalid filters ─────────────────────────────────────────────────

    #[Test]
    public function filterWithIntegerValueThrows(): void
    {
        $cfg = self::minimalConfig();
        $cfg['filters'] = ['title' => 42];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('filter "title"');
        ApiDefinition::fromArray($cfg);
    }

    #[Test]
    public function filterWithClassStringIsAccepted(): void
    {
        $cfg = self::minimalConfig();
        $cfg['filters'] = ['title' => ExactFilter::class];
        $def = ApiDefinition::fromArray($cfg);
        self::assertInstanceOf(FilterDefinition::class, $def->filters['title']);
        self::assertSame(ExactFilter::class, $def->filters['title']->filterClass);
    }

    #[Test]
    public function filterWithClassAndOptionsArrayIsAccepted(): void
    {
        $cfg = self::minimalConfig();
        $cfg['filters'] = ['search' => [SearchFilter::class, ['columns' => ['title', 'body']]]];
        $def = ApiDefinition::fromArray($cfg);
        self::assertInstanceOf(FilterDefinition::class, $def->filters['search']);
        self::assertSame(SearchFilter::class, $def->filters['search']->filterClass);
        self::assertSame(['title', 'body'], $def->filters['search']->option('columns'));
    }

    #[Test]
    public function filterWithInvalidOptionsTypeThrows(): void
    {
        $cfg = self::minimalConfig();
        $cfg['filters'] = ['title' => [ExactFilter::class, 123]];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('filter "title" options');
        ApiDefinition::fromArray($cfg);
    }

    // ── Invalid order ───────────────────────────────────────────────────

    #[Test]
    public function orderAllowedNotArrayThrows(): void
    {
        $cfg = self::minimalConfig();
        $cfg['order'] = ['allowed' => 'title'];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('order.allowed');
        ApiDefinition::fromArray($cfg);
    }

    #[Test]
    public function orderDefaultInvalidDirectionThrows(): void
    {
        $cfg = self::minimalConfig();
        $cfg['order'] = ['default' => ['uid' => 'sideways']];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('order.default["uid"]');
        ApiDefinition::fromArray($cfg);
    }

    // ── Invalid writeMode ───────────────────────────────────────────────

    #[Test]
    public function invalidWriteModeThrows(): void
    {
        $cfg = self::minimalConfig();
        $cfg['general']['writeMode'] = 'god_mode';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('writeMode');
        ApiDefinition::fromArray($cfg);
    }

    // ── Column validation errors are wrapped ─────────────────────────────

    #[Test]
    public function invalidColumnConfigIsPropagatedWithContext(): void
    {
        $cfg = self::minimalConfig();
        $cfg['columns'] = ['title' => ['type' => 'blob']];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('column "title"');
        ApiDefinition::fromArray($cfg);
    }

    // ── Invalid ownership ───────────────────────────────────────────────

    #[Test]
    public function ownershipColumnEmptyStringThrows(): void
    {
        $cfg = self::minimalConfig();
        $cfg['ownership'] = ['column' => ''];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('ownership.column');
        ApiDefinition::fromArray($cfg);
    }

    // ── Language mode ───────────────────────────────────────────────────

    #[Test]
    public function languageModeDefaultsToAutoWhenLanguageSectionOmitted(): void
    {
        $def = ApiDefinition::fromArray(self::minimalConfig());

        self::assertSame('auto', $def->languageMode);
    }

    #[Test]
    public function languageModeAutoIsAccepted(): void
    {
        $cfg = self::minimalConfig();
        $cfg['general']['language'] = ['mode' => 'auto'];

        $def = ApiDefinition::fromArray($cfg);

        self::assertSame('auto', $def->languageMode);
    }

    #[Test]
    public function languageModeIgnoreIsAccepted(): void
    {
        $cfg = self::minimalConfig();
        $cfg['general']['language'] = ['mode' => 'ignore'];

        $def = ApiDefinition::fromArray($cfg);

        self::assertSame('ignore', $def->languageMode);
    }

    #[Test]
    public function languageSectionMustBeAnArray(): void
    {
        $cfg = self::minimalConfig();
        $cfg['general']['language'] = 'auto';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('general.language must be an array');
        ApiDefinition::fromArray($cfg);
    }

    #[Test]
    public function unknownLanguageModeStringIsRejected(): void
    {
        $cfg = self::minimalConfig();
        $cfg['general']['language'] = ['mode' => 'strict'];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('general.language.mode has invalid value "strict"');
        ApiDefinition::fromArray($cfg);
    }

    #[Test]
    public function nonStringLanguageModeIsRejected(): void
    {
        $cfg = self::minimalConfig();
        $cfg['general']['language'] = ['mode' => 42];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('general.language.mode has invalid value "int"');
        ApiDefinition::fromArray($cfg);
    }

    #[Test]
    public function emptyLanguageArrayDefaultsToAuto(): void
    {
        $cfg = self::minimalConfig();
        $cfg['general']['language'] = [];

        $def = ApiDefinition::fromArray($cfg);

        self::assertSame('auto', $def->languageMode);
    }

    #[Test]
    public function nullLanguageModeFallsBackToAuto(): void
    {
        $cfg = self::minimalConfig();
        $cfg['general']['language'] = ['mode' => null];

        $def = ApiDefinition::fromArray($cfg);

        self::assertSame('auto', $def->languageMode);
    }

    #[Test]
    public function emptyStringLanguageModeIsRejected(): void
    {
        $cfg = self::minimalConfig();
        $cfg['general']['language'] = ['mode' => ''];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('general.language.mode has invalid value ""');
        ApiDefinition::fromArray($cfg);
    }

    // ── group (OpenAPI tag) ─────────────────────────────────────────────

    #[Test]
    public function groupDefaultsToNullAndTagNameFallsBackToResourceType(): void
    {
        $def = ApiDefinition::fromArray(self::minimalConfig());

        self::assertNull($def->group);
        self::assertNull($def->groupDescription);
        self::assertSame('Test', $def->tagName());
    }

    #[Test]
    public function groupAsStringSetsTagName(): void
    {
        $cfg = self::minimalConfig();
        $cfg['general']['group'] = 'Editorial';

        $def = ApiDefinition::fromArray($cfg);

        self::assertSame('Editorial', $def->group);
        self::assertNull($def->groupDescription);
        self::assertSame('Editorial', $def->tagName());
    }

    #[Test]
    public function groupAsArrayWithNameAndDescription(): void
    {
        $cfg = self::minimalConfig();
        $cfg['general']['group'] = ['name' => 'Editorial', 'description' => 'Editorial content endpoints'];

        $def = ApiDefinition::fromArray($cfg);

        self::assertSame('Editorial', $def->group);
        self::assertSame('Editorial content endpoints', $def->groupDescription);
        self::assertSame('Editorial', $def->tagName());
    }

    #[Test]
    public function groupAsArrayWithoutDescriptionKeepsDescriptionNull(): void
    {
        $cfg = self::minimalConfig();
        $cfg['general']['group'] = ['name' => 'Editorial'];

        $def = ApiDefinition::fromArray($cfg);

        self::assertSame('Editorial', $def->group);
        self::assertNull($def->groupDescription);
    }

    #[Test]
    public function emptyStringGroupThrows(): void
    {
        $cfg = self::minimalConfig();
        $cfg['general']['group'] = '';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('general.group must be a non-empty string');
        ApiDefinition::fromArray($cfg);
    }

    #[Test]
    public function groupArrayWithoutNameThrows(): void
    {
        $cfg = self::minimalConfig();
        $cfg['general']['group'] = ['description' => 'no name here'];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('general.group.name must be a non-empty string');
        ApiDefinition::fromArray($cfg);
    }

    #[Test]
    public function groupArrayWithEmptyDescriptionThrows(): void
    {
        $cfg = self::minimalConfig();
        $cfg['general']['group'] = ['name' => 'Editorial', 'description' => ''];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('general.group.description must be a non-empty string');
        ApiDefinition::fromArray($cfg);
    }

    #[Test]
    public function groupWithInvalidScalarTypeThrows(): void
    {
        $cfg = self::minimalConfig();
        $cfg['general']['group'] = 42;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('general.group must be a string or an array');
        ApiDefinition::fromArray($cfg);
    }
}
