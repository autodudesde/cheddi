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

use AutoDudes\AiSuite\Service\BackendUserService;
use AutoDudes\AiSuite\Service\MetadataService;
use AutoDudes\AiSuite\Service\RoutePermissionService;
use AutoDudes\Cheddi\Domain\Repository\OpenItemRuleRepository;
use AutoDudes\Cheddi\Enumeration\OpenItemRuleComparisonEnumeration as Comparison;
use AutoDudes\Cheddi\Service\Chat\OpenItem\RuleSource\LanguageGapsRuleSource;
use AutoDudes\Cheddi\Service\Chat\OpenItem\RuleSource\OpenItemRuleSources;
use TYPO3\CMS\Backend\Module\ModuleInterface;
use TYPO3\CMS\Backend\Module\ModuleProvider;
use TYPO3\CMS\Backend\Routing\Router;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

class OpenItemActions
{
    public const KIND_WORKFLOW = 'workflow';
    public const KIND_AUDIT = 'audit';

    private const MODULE = 'web_aisuite';
    private const PAGES_ROUTE = 'ai_suite_workflow_pages_prepare';
    private const FILES_ROUTE = 'files_aisuite.files_prepare';
    private const RESET_FOLDER_SELECTION = 'aiSuiteResetFolderSelection';
    private const TRANSLATION_ROUTE = 'ai_suite_workflow_pages_translation_prepare';
    private const AUDIT_ROUTE = 'ai_suite_audit_cached';
    private const AUDIT_MODULE_ROUTE = 'web_aisuite.audit';
    private const AUDIT_SOURCE = 'audit';
    private const AUDIT_TYPES = ['seo', 'a11y'];
    private const PRESELECT = 'aiSuitePreselect';
    private const SUBTREE_DEPTH = 5;
    private const EMPTY_COMPARISONS = [Comparison::IS_EMPTY, Comparison::EFFECTIVE_EMPTY];

    /**
     * @var array<int, null|array{route: string, column: string, auditType: string}>
     */
    private array $targets = [];

    /**
     * @var array<int, null|array<string, mixed>>
     */
    private array $rules = [];

    /**
     * @var array<string, bool>
     */
    private array $granted = [];

    /**
     * @var array<int, bool>
     */
    private array $workablePages = [];

    public function __construct(
        private readonly OpenItemRuleRepository $ruleRepository,
        private readonly BackendUserService $backendUserService,
        private readonly ModuleProvider $moduleProvider,
        private readonly RoutePermissionService $routePermission,
        private readonly Router $router,
        private readonly UriBuilder $uriBuilder,
        private readonly SiteFinder $siteFinder,
        private readonly ResourceFactory $resourceFactory,
    ) {}

    /**
     * @param array{kind: string, item_key: string, page_uid: int, record_table: string, record_uid: int} $row
     *
     * @return null|array{kind: string, url: string}
     */
    public function forItem(array $row): ?array
    {
        $target = $this->targetOf($row);
        if (null === $target) {
            return null;
        }

        return match ($target['route']) {
            self::PAGES_ROUTE => $this->pageMetadataItem($row, $target['column']),
            self::FILES_ROUTE => $this->fileMetadataLink($row, $target['column'], false),
            self::TRANSLATION_ROUTE => $this->translationLink($row, $row['page_uid'], 0),
            self::AUDIT_ROUTE => $this->granted(self::AUDIT_ROUTE) && $this->mayWorkOn($row['page_uid']) ? [
                'kind' => self::KIND_AUDIT,
                'url' => (string) $this->uriBuilder->buildUriFromRoute(self::AUDIT_ROUTE, ['pageId' => $row['page_uid'], 'auditType' => $target['auditType'], 'languageUid' => 0]),
            ] : null,
            default => null,
        };
    }

    /**
     * @param array{kind: string, item_key: string, page_uid: int, record_table: string, record_uid: int} $row
     *
     * @return null|array{kind: string, url: string}
     */
    public function forTask(array $row): ?array
    {
        $target = $this->targetOf($row);
        if (null === $target) {
            return null;
        }
        if (self::FILES_ROUTE === $target['route']) {
            return $this->fileMetadataLink($row, $target['column'], true);
        }
        $rootPage = $this->rootPageOf($row);

        return match ($target['route']) {
            self::PAGES_ROUTE => $this->link(self::KIND_WORKFLOW, $target['route'], $rootPage, [
                'column' => $target['column'],
                'showOnlyEmpty' => '1',
                'depth' => (string) self::SUBTREE_DEPTH,
            ]),
            self::TRANSLATION_ROUTE => $this->translationLink($row, $rootPage, self::SUBTREE_DEPTH),
            self::AUDIT_ROUTE => $this->granted(self::AUDIT_MODULE_ROUTE) && $this->mayWorkOn($rootPage) ? [
                'kind' => self::KIND_AUDIT,
                'url' => (string) $this->uriBuilder->buildUriFromRoute(self::AUDIT_MODULE_ROUTE, ['id' => $rootPage]),
            ] : null,
            default => null,
        };
    }

    /**
     * @param array{kind: string, item_key: string, page_uid: int, record_table: string, record_uid: int} $row
     *
     * @return null|array{kind: string, url: string}
     */
    private function pageMetadataItem(array $row, string $column): ?array
    {
        $language = $this->languageOf('pages', $row['record_uid']);
        $pageUid = $row['record_uid'];
        if ($language > 0) {
            $pageUid = (int) (BackendUtility::getRecord('pages', $row['record_uid'], 'l10n_parent')['l10n_parent'] ?? 0);
        }

        return $this->link(self::KIND_WORKFLOW, self::PAGES_ROUTE, $pageUid, [
            'column' => $column,
            'showOnlyEmpty' => '1',
            'depth' => '0',
            'sysLanguage' => (string) $language,
        ]);
    }

    /**
     * @param array{kind: string, item_key: string, page_uid: int, record_table: string, record_uid: int} $row
     *
     * @return null|array{kind: string, url: string}
     */
    private function fileMetadataLink(array $row, string $column, bool $wholeStorage): ?array
    {
        if (!$this->granted(self::FILES_ROUTE)) {
            return null;
        }
        $file = $this->fileOf($row);
        if (null === $file || !$file->checkActionPermission('write')) {
            return null;
        }
        $folder = $wholeStorage ? $file->getStorage()->getRootLevelFolder() : $file->getParentFolder();

        return ['kind' => self::KIND_WORKFLOW, 'url' => (string) $this->uriBuilder->buildUriFromRoute(self::FILES_ROUTE, [
            'id' => $folder->getCombinedIdentifier(),
            self::RESET_FOLDER_SELECTION => '1',
            self::PRESELECT => [
                'column' => $column,
                'showOnlyEmpty' => '1',
                'showOnlyUsed' => '1',
                'depth' => $wholeStorage ? (string) self::SUBTREE_DEPTH : '0',
                'sysLanguage' => (string) $this->languageOf($row['record_table'], $row['record_uid']),
            ],
        ])];
    }

    /**
     * @param array{record_table: string, record_uid: int} $row
     */
    private function fileOf(array $row): ?File
    {
        $fileUid = (int) (BackendUtility::getRecord('sys_file_reference', $row['record_uid'], 'uid_local')['uid_local'] ?? 0);
        if ('sys_file_reference' !== $row['record_table'] || $fileUid <= 0) {
            return null;
        }

        try {
            return $this->resourceFactory->getFileObject($fileUid);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array{kind: string, item_key: string, page_uid: int, record_table: string, record_uid: int} $row
     *
     * @return null|array{kind: string, url: string}
     */
    private function translationLink(array $row, int $pageUid, int $depth): ?array
    {
        $position = strpos($row['item_key'], LanguageGapsRuleSource::KEY_PREFIX);
        $language = false === $position ? 0 : (int) substr($row['item_key'], $position + strlen(LanguageGapsRuleSource::KEY_PREFIX));
        if ($language <= 0) {
            return null;
        }

        return $this->link(self::KIND_WORKFLOW, self::TRANSLATION_ROUTE, $pageUid, [
            'depth' => (string) $depth,
            'sourceLanguage' => '0',
            'targetLanguage' => (string) $language,
            'translationScope' => 'all',
        ]);
    }

    /**
     * @param array<string, string> $preselect
     *
     * @return null|array{kind: string, url: string}
     */
    private function link(string $kind, string $route, int $pageUid, array $preselect): ?array
    {
        if (!$this->granted($route) || !$this->mayWorkOn($pageUid)) {
            return null;
        }

        return ['kind' => $kind, 'url' => (string) $this->uriBuilder->buildUriFromRoute($route, ['id' => $pageUid, self::PRESELECT => $preselect])];
    }

    private function granted(string $route): bool
    {
        if (!array_key_exists($route, $this->granted)) {
            $backendUser = $this->backendUserService->getBackendUser();
            $routeObject = $this->router->hasRoute($route) ? $this->router->getRoute($route) : null;
            $module = $routeObject?->getOption('module');
            $this->granted[$route] = null !== $backendUser
                && null !== $routeObject
                && $this->moduleProvider->accessGranted($module instanceof ModuleInterface ? $module->getIdentifier() : self::MODULE, $backendUser)
                && $this->routePermission->isGranted($routeObject->getOptions(), $backendUser);
        }

        return $this->granted[$route];
    }

    private function mayWorkOn(int $pageUid): bool
    {
        if (!array_key_exists($pageUid, $this->workablePages)) {
            $backendUser = $this->backendUserService->getBackendUser();
            $this->workablePages[$pageUid] = $pageUid > 0
                && null !== $backendUser
                && $this->hasSite($pageUid)
                && null !== $backendUser->isInWebMount($pageUid)
                && is_array(BackendUtility::readPageAccess($pageUid, $backendUser->getPagePermsClause(Permission::PAGE_EDIT)));
        }

        return $this->workablePages[$pageUid];
    }

    private function hasSite(int $pageUid): bool
    {
        try {
            $this->siteFinder->getSiteByPageId($pageUid);

            return true;
        } catch (SiteNotFoundException) {
            return false;
        }
    }

    /**
     * @param array{kind: string, item_key: string, page_uid: int} $row
     */
    private function rootPageOf(array $row): int
    {
        $rootPage = (int) ($this->ruleOf($row)['root_page'] ?? 0);
        if ($rootPage > 0) {
            return $rootPage;
        }

        try {
            return $this->siteFinder->getSiteByPageId($row['page_uid'])->getRootPageId();
        } catch (SiteNotFoundException) {
            return 0;
        }
    }

    private function languageOf(string $table, int $uid): int
    {
        $field = (string) ($GLOBALS['TCA'][$table]['ctrl']['languageField'] ?? '');
        if ('' === $field || $uid <= 0) {
            return 0;
        }

        return max(0, (int) (BackendUtility::getRecord($table, $uid, $field)[$field] ?? 0));
    }

    /**
     * @param array{kind: string, item_key: string} $row
     *
     * @return null|array{route: string, column: string, auditType: string}
     */
    private function targetOf(array $row): ?array
    {
        if (CustomRulesSource::KIND !== $row['kind']) {
            return null;
        }
        $ruleUid = (int) (explode(':', $row['item_key'])[1] ?? 0);
        if (!array_key_exists($ruleUid, $this->targets)) {
            $rule = $this->ruleOf($row);
            $this->targets[$ruleUid] = null === $rule ? null : $this->targetOfRule($rule);
        }

        return $this->targets[$ruleUid];
    }

    /**
     * @param array<string, mixed> $rule
     *
     * @return null|array{route: string, column: string, auditType: string}
     */
    private function targetOfRule(array $rule): ?array
    {
        $source = (string) ($rule['source'] ?? '');
        if (LanguageGapsRuleSource::IDENTIFIER === $source) {
            return ['route' => self::TRANSLATION_ROUTE, 'column' => '', 'auditType' => ''];
        }
        if (self::AUDIT_SOURCE === $source) {
            $auditType = (string) (OpenItemRuleSources::parametersOf($rule)['audit_type'] ?? '');

            return in_array($auditType, self::AUDIT_TYPES, true) ? ['route' => self::AUDIT_ROUTE, 'column' => '', 'auditType' => $auditType] : null;
        }
        if ('' !== $source) {
            return null;
        }

        [$route, $columns] = match ((string) ($rule['source_table'] ?? '')) {
            'pages' => [self::PAGES_ROUTE, MetadataService::PAGE_METADATA_COLUMNS],
            'sys_file_reference' => [self::FILES_ROUTE, MetadataService::FILE_METADATA_COLUMNS],
            default => ['', []],
        };
        foreach ($rule['conditions'] ?? [] as $condition) {
            $field = (string) ($condition['field_name'] ?? '');
            if (in_array((string) ($condition['comparison'] ?? ''), self::EMPTY_COMPARISONS, true) && in_array($field, $columns, true)) {
                return ['route' => $route, 'column' => $field, 'auditType' => ''];
            }
        }

        return null;
    }

    /**
     * @param array{kind: string, item_key: string} $row
     *
     * @return null|array<string, mixed>
     */
    private function ruleOf(array $row): ?array
    {
        $ruleUid = (int) (explode(':', $row['item_key'])[1] ?? 0);
        if (!array_key_exists($ruleUid, $this->rules)) {
            $this->rules[$ruleUid] = $this->ruleRepository->findRule($ruleUid);
        }

        return $this->rules[$ruleUid];
    }
}
