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

namespace AutoDudes\Cheddi\Service\Chat\OpenItem;

use AutoDudes\Cheddi\Enumeration\CheddiPermissionEnumeration;
use AutoDudes\Cheddi\Enumeration\OpenItemRuleComparisonEnumeration;
use AutoDudes\Cheddi\Service\Chat\OpenItem\RuleSource\OpenItemRuleSources;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

class OpenItemRuleAccess
{
    public function __construct(
        private readonly OpenItemRuleTables $ruleTables,
        private readonly OpenItemRuleSources $ruleSources,
    ) {}

    public function mayManage(BackendUserAuthentication $backendUser): bool
    {
        return $backendUser->isAdmin() || $backendUser->check('custom_options', CheddiPermissionEnumeration::OPEN_ITEM_RULES);
    }

    /**
     * @return list<string>
     */
    public function tablesFor(BackendUserAuthentication $backendUser): array
    {
        return array_values(array_filter(
            $this->ruleTables->selectable(),
            fn (string $table): bool => $this->mayUseTable($backendUser, $table),
        ));
    }

    /**
     * @return list<string>
     */
    public function fieldsFor(BackendUserAuthentication $backendUser, string $table): array
    {
        if (!$this->mayUseTable($backendUser, $table)) {
            return [];
        }

        return array_values(array_filter(
            $this->ruleTables->fieldsOf($table),
            fn (string $field): bool => $this->mayUseField($backendUser, $table, $field),
        ));
    }

    public function mayUseField(BackendUserAuthentication $backendUser, string $table, string $field): bool
    {
        if ($backendUser->isAdmin() || true !== ($GLOBALS['TCA'][$table]['columns'][$field]['exclude'] ?? false)) {
            return true;
        }

        return $backendUser->check('non_exclude_fields', $table.':'.$field);
    }

    public function mayUseScope(BackendUserAuthentication $backendUser, int $rootPage): bool
    {
        if ($backendUser->isAdmin()) {
            return true;
        }

        return $rootPage > 0 && null !== $backendUser->isInWebMount($rootPage, $backendUser->getPagePermsClause(Permission::PAGE_SHOW));
    }

    /**
     * @param array<string, mixed> $rule
     */
    public function mayEdit(BackendUserAuthentication $backendUser, array $rule): bool
    {
        return $this->mayUseScope($backendUser, (int) ($rule['root_page'] ?? 0)) && $this->mayUseDefinition($backendUser, $rule);
    }

    /**
     * @param array<string, mixed> $rule
     */
    public function mayUseDefinition(BackendUserAuthentication $backendUser, array $rule): bool
    {
        if (!$this->mayManage($backendUser)) {
            return false;
        }

        $sourceIdentifier = (string) ($rule['source'] ?? '');
        if ('' !== $sourceIdentifier) {
            return null !== ($source = $this->ruleSources->get($sourceIdentifier)) && $source->mayUse($backendUser);
        }

        $table = (string) ($rule['source_table'] ?? '');
        if (!$this->mayUseTable($backendUser, $table)) {
            return false;
        }

        foreach ($rule['conditions'] ?? [] as $condition) {
            if (!$this->mayUseCondition($backendUser, $table, $condition)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $condition
     */
    private function mayUseCondition(BackendUserAuthentication $backendUser, string $table, array $condition): bool
    {
        $comparison = (string) ($condition['comparison'] ?? '');
        if (OpenItemRuleComparisonEnumeration::OLDER_THAN_DAYS === $comparison) {
            return true;
        }
        if (OpenItemRuleComparisonEnumeration::HAS_NO_RELATED === $comparison) {
            return $this->mayUseTable($backendUser, (string) ($condition['related_table'] ?? ''));
        }

        return $this->mayUseField($backendUser, $table, (string) ($condition['field_name'] ?? ''));
    }

    private function mayUseTable(BackendUserAuthentication $backendUser, string $table): bool
    {
        return $this->ruleTables->isAllowed($table) && ($backendUser->isAdmin() || $backendUser->check('tables_select', $table));
    }
}
