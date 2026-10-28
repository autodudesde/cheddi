<?php

declare(strict_types=1);

/*
 *
 * This file is part of the "cheddi" Extension for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 *
 */

namespace AutoDudes\Cheddi\Upgrades;

use AutoDudes\Cheddi\Enumeration\CheddiPermissionEnumeration;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Install\Updates\DatabaseUpdatedPrerequisite;
use TYPO3\CMS\Install\Updates\UpgradeWizardInterface;

final class CheddiPermissionsUpgradeWizard implements UpgradeWizardInterface
{
    public const IDENTIFIER = 'cheddiSeparatePermissions';

    private const TABLE = 'be_groups';

    private const LEGACY_FEATURES = 'tx_aisuite_features';
    private const LEGACY_MODELS = 'tx_aisuite_models';

    /**
     * @var array<string, list<string>>
     */
    private const DERIVED_FROM_AI_SUITE = [
        CheddiPermissionEnumeration::GENERATION => [
            self::LEGACY_FEATURES.':enable_metadata_generation',
            self::LEGACY_FEATURES.':enable_content_element_generation',
            self::LEGACY_FEATURES.':enable_pages_generation',
        ],
        CheddiPermissionEnumeration::TRANSLATION => [self::LEGACY_FEATURES.':enable_translation'],
        CheddiPermissionEnumeration::IMAGE_GENERATION => [self::LEGACY_FEATURES.':enable_image_generation'],
        CheddiPermissionEnumeration::AUDIT => [self::LEGACY_FEATURES.':enable_audit'],
        CheddiPermissionEnumeration::RENDERED_PAGE_READ => [self::LEGACY_FEATURES.':enable_mcp_rendered_page_read'],
    ];

    private const SPLIT_FROM_TRANSLATION = [
        CheddiPermissionEnumeration::PAGE_TRANSLATION,
        CheddiPermissionEnumeration::FILE_METADATA_TRANSLATION,
    ];

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    public function getTitle(): string
    {
        return 'ChEddi: separate the backend group permissions from the AI Suite';
    }

    public function getDescription(): string
    {
        return 'Moves the ChEddi flags and chat models out of "AI Suite Features" and "AI Suite Models" into their own '
            .'"ChEddi Features" and "ChEddi Models" lists, and grants every group the ChEddi counterpart of the AI Suite '
            .'features it holds (translation, generation, images, audits, rendered pages), so nobody loses an ability in the chat. '
            .'A group that translates in ChEddi also gets the separate flags for whole pages and file metadata.';
    }

    public function executeUpdate(): bool
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        foreach ($this->groups() as $uid => $customOptions) {
            $migrated = self::migrate($customOptions);
            if ($migrated !== $customOptions) {
                $connection->update(self::TABLE, ['custom_options' => $migrated], ['uid' => $uid]);
            }
        }

        return true;
    }

    public function updateNecessary(): bool
    {
        foreach ($this->groups() as $customOptions) {
            $options = self::split($customOptions);
            if ([] !== array_intersect($options, array_keys(self::renames()))) {
                return true;
            }
            if (in_array(CheddiPermissionEnumeration::TRANSLATION, $options, true) && [] !== array_diff(self::SPLIT_FROM_TRANSLATION, $options)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public function getPrerequisites(): array
    {
        return [DatabaseUpdatedPrerequisite::class];
    }

    public static function migrate(string $customOptions): string
    {
        $options = self::split($customOptions);
        $renames = self::renames();

        $migrated = [];
        foreach ($options as $option) {
            $migrated[] = $renames[$option] ?? $option;
        }
        foreach (self::DERIVED_FROM_AI_SUITE as $cheddiFlag => $sources) {
            if ([] !== array_intersect($options, $sources)) {
                $migrated[] = $cheddiFlag;
            }
        }
        if (in_array(CheddiPermissionEnumeration::TRANSLATION, $migrated, true)) {
            array_push($migrated, ...self::SPLIT_FROM_TRANSLATION);
        }

        return implode(',', array_values(array_unique($migrated)));
    }

    /**
     * @return array<string, string>
     */
    private static function renames(): array
    {
        $renames = [
            self::LEGACY_FEATURES.':enable_cheddi_interface' => CheddiPermissionEnumeration::INTERFACE,
            self::LEGACY_FEATURES.':enable_web_research' => CheddiPermissionEnumeration::WEB_RESEARCH,
        ];
        foreach (CheddiPermissionEnumeration::CHAT_MODELS as $model) {
            $renames[self::LEGACY_MODELS.':'.$model] = CheddiPermissionEnumeration::model($model);
        }

        return $renames;
    }

    /**
     * @return list<string>
     */
    private static function split(string $customOptions): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $customOptions)), static fn (string $option): bool => '' !== $option));
    }

    /**
     * @return array<int, string>
     */
    private function groups(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();
        $rows = $queryBuilder
            ->select('uid', 'custom_options')
            ->from(self::TABLE)
            ->where($queryBuilder->expr()->neq('custom_options', $queryBuilder->createNamedParameter('')))
            ->executeQuery()
            ->fetchAllAssociative()
        ;

        $groups = [];
        foreach ($rows as $row) {
            $groups[(int) $row['uid']] = (string) $row['custom_options'];
        }

        return $groups;
    }
}
