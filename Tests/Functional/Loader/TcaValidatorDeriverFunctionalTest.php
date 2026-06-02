<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Loader;

use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Functional verification that TcaValidatorDeriver runs at boot time and
 * gap-fills validators from TCA into the registered ApiDefinition.
 *
 * Uses the real `articles` resource (Configuration/TcaApi/Articles.php) and
 * the real TCA fixture (Configuration/TCA/tx_myext_domain_model_article.php).
 */
final class TcaValidatorDeriverFunctionalTest extends ApiFunctionalTestCase
{
    #[Test]
    public function profilePhotoGetsMaxItemsValidatorDerivedFromTcaFileMaxitems(): void
    {
        // Articles.php declares profile_photo without validators; the TCA
        // entry for tx_myext_domain_model_article.profile_photo declares
        // maxitems=1 — the deriver must inject a maxItems validator.
        $def = $this->getApiRegistry()->get('articles');
        self::assertNotNull($def);

        $profilePhoto = $def->columns['profile_photo'] ?? null;
        self::assertNotNull($profilePhoto, 'profile_photo column must be registered');

        $maxItems = self::findValidator($profilePhoto->validators, 'maxItems');
        self::assertNotNull($maxItems, 'maxItems validator must be auto-derived');
        self::assertSame(1, (int)$maxItems['max']);
    }

    #[Test]
    public function titleExplicitMaxLengthWinsOverTcaMax(): void
    {
        // TCA has max=255 for title, but Configuration/TcaApi/Articles.php
        // declares an explicit maxLength=20 — explicit must win (gap-fill proof).
        $def = $this->getApiRegistry()->get('articles');
        self::assertNotNull($def);

        $title = $def->columns['title'] ?? null;
        self::assertNotNull($title);

        $maxLengthValidators = array_values(array_filter(
            $title->validators,
            static fn (array $v): bool => ($v['type'] ?? null) === 'maxLength',
        ));

        self::assertCount(1, $maxLengthValidators, 'Only the explicit maxLength must remain');
        self::assertSame(20, (int)$maxLengthValidators[0]['max']);
    }

    #[Test]
    public function firstNameGetsMaxLengthAutoDerivedFromTca(): void
    {
        // TCA has input.max=255 for first_name. Articles.php declares
        // first_name with only `groups` — no explicit validators. The deriver
        // must inject a maxLength=255 validator.
        $def = $this->getApiRegistry()->get('articles');
        self::assertNotNull($def);

        $firstName = $def->columns['first_name'] ?? null;
        self::assertNotNull($firstName, 'first_name column must be registered');

        $maxLength = self::findValidator($firstName->validators, 'maxLength');
        self::assertNotNull($maxLength, 'maxLength validator must be auto-derived for first_name');
        self::assertSame(255, (int)$maxLength['max']);
    }

    #[Test]
    public function defaultModeResourceLeavesColumnsEmpty(): void
    {
        // colors-validate-default (Configuration/TcaApi/ColorsDefaultWrite.php) has no
        // explicit columns — default mode. The deriver no longer stub-injects entries
        // for undeclared TCA columns; the end-to-end enforcement is covered by
        // DefaultModeValidationTest, which exercises FieldValidator's on-demand path.
        $def = $this->getApiRegistry()->get('colors-validate-default');
        self::assertNotNull($def, 'colors-validate-default resource must be registered');
        self::assertFalse($def->isExplicitMode);
        self::assertSame([], $def->columns);
    }

    /**
     * @param array<int, array<string, mixed>> $validators
     * @return array<string, mixed>|null
     */
    private static function findValidator(array $validators, string $type): ?array
    {
        foreach ($validators as $validator) {
            if (($validator['type'] ?? null) === $type) {
                return $validator;
            }
        }
        return null;
    }
}
