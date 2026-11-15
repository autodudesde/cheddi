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

namespace AutoDudes\Cheddi\Service\Chat;

use AutoDudes\AiSuite\Service\BackendUserService;
use AutoDudes\Cheddi\Domain\Repository\OpenItemRepository;
use AutoDudes\Cheddi\Enumeration\CheddiPermissionEnumeration;
use AutoDudes\Cheddi\Enumeration\OpenItemAreaEnumeration;
use AutoDudes\Cheddi\Service\Chat\OpenItem\CustomRulesSource;
use AutoDudes\Cheddi\Service\Chat\OpenItem\Fix\OpenItemFixTargets;
use AutoDudes\Cheddi\Service\Chat\OpenItem\OpenItemActions;
use AutoDudes\Cheddi\Service\Chat\OpenItem\OpenItemChatPrompts;
use AutoDudes\Cheddi\Service\Chat\OpenItem\OpenItemRuleAccess;
use AutoDudes\Cheddi\Service\Chat\OpenItem\OpenItemSource;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Resource\ProcessedFile;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

class OpenItemService
{
    public const DISPLAY_LIMIT = 100;
    public const RESOLVED_MARKED = 'marked';
    public const RESOLVED_ACCEPTED = 'accepted';
    private const THUMBNAIL_SIZE = 96;

    private const SEVERITY_RANK = ['notice' => 1, 'warning' => 2, 'error' => 3];

    /**
     * @var array<int, null|string>
     */
    private array $pageTitles = [];

    /**
     * @var array<string, OpenItemSource>
     */
    private array $sources = [];

    /**
     * @param iterable<OpenItemSource> $sources
     */
    public function __construct(
        private readonly OpenItemRepository $openItems,
        private readonly BackendUserService $backendUserService,
        private readonly UriBuilder $uriBuilder,
        private readonly OpenItemRuleAccess $ruleAccess,
        private readonly OpenItemFixTargets $fixTargets,
        private readonly OpenItemActions $actions,
        private readonly OpenItemChatPrompts $chatPrompts,
        private readonly ResourceFactory $resourceFactory,
        #[AutowireIterator(OpenItemSource::TAG)]
        iterable $sources = [],
    ) {
        foreach ($sources as $source) {
            $this->sources[$source->kind()] = $source;
        }
    }

    /**
     * @param array{message: string, reference_ts: int, severity: string} $row
     */
    public static function fingerprintOf(array $row): string
    {
        return hash('sha1', $row['severity'].'|'.$row['reference_ts'].'|'.$row['message']);
    }

    /**
     * @return array<string, mixed>
     */
    public function forCurrentUser(): array
    {
        $empty = ['total' => 0, 'tasks' => [], 'items' => [], 'canManage' => $this->canManageRules()];
        $backendUser = $this->backendUserService->getBackendUser();
        if (null === $backendUser || !$this->backendUserService->checkPermissions(CheddiPermissionEnumeration::OPEN_ITEMS)) {
            return $empty;
        }

        $resolved = $this->openItems->findResolvedFingerprints();
        $total = 0;
        $items = [];
        $tasks = [];
        foreach ($this->openItems->findAllOldestFirst() as $row) {
            if (!isset($this->sources[$row['kind']]) || ($resolved[$row['uid']] ?? null) === self::fingerprintOf($row)) {
                continue;
            }
            $title = $this->readablePageTitle($row['page_uid']);
            if (null === $title || !$this->mayReadTable($row['record_table']) || !$this->mayShowFromRule($row)) {
                continue;
            }
            ++$total;

            $item = $this->present($row, $title);
            $key = $item['groupKey'];
            $tasks[$key] ??= ['key' => $key, 'label' => $item['group'], 'count' => 0, 'fix' => $item['fix'], 'action' => null, 'severity' => 'notice'];
            $tasks[$key]['action'] ??= $this->actions->forTask($row);
            ++$tasks[$key]['count'];
            if ((self::SEVERITY_RANK[$row['severity']] ?? 1) > (self::SEVERITY_RANK[$tasks[$key]['severity']] ?? 1)) {
                $tasks[$key]['severity'] = $row['severity'];
            }
            if ($tasks[$key]['count'] <= self::DISPLAY_LIMIT) {
                $items[] = $item;
            }
        }

        $tasks = array_values($tasks);
        $collator = class_exists(\Collator::class) ? new \Collator('') : null;
        usort($tasks, static fn (array $a, array $b): int => null !== $collator ? (int) $collator->compare($a['label'], $b['label']) : strnatcasecmp($a['label'], $b['label']));

        return ['total' => $total, 'tasks' => $tasks, 'items' => $items, 'canManage' => $this->canManageRules()];
    }

    /**
     * @param array{kind: string, item_key: string, page_uid: int, record_table: string} $row
     */
    public function isVisible(array $row): bool
    {
        return isset($this->sources[$row['kind']])
            && $this->backendUserService->checkPermissions(CheddiPermissionEnumeration::OPEN_ITEMS)
            && null !== $this->readablePageTitle($row['page_uid'])
            && $this->mayReadTable($row['record_table'])
            && $this->mayShowFromRule($row);
    }

    public function resolve(int $itemUid, string $mode = self::RESOLVED_MARKED): bool
    {
        $row = $this->openItems->findItem($itemUid);
        $backendUser = $this->backendUserService->getBackendUser();
        if (null === $row || null === $backendUser || !$this->mayEditPage($row['page_uid'])) {
            return false;
        }

        $this->openItems->addResolution($itemUid, self::fingerprintOf($row), (int) ($backendUser->user['uid'] ?? 0), OpenItemAreaEnumeration::normalize($row['area']), $mode, time());

        return true;
    }

    private function mayEditPage(int $pageUid): bool
    {
        $backendUser = $this->backendUserService->getBackendUser();
        if (null === $backendUser) {
            return false;
        }
        $page = BackendUtility::readPageAccess($pageUid, $backendUser->getPagePermsClause(Permission::CONTENT_EDIT));

        return is_array($page);
    }

    /**
     * @param array{uid: int, kind: string, page_uid: int, item_key: string, severity: string, message: string, reference_ts: int, record_table: string, record_uid: int, area: string} $row
     *
     * @return array{uid: int, kind: string, pageId: int, pageTitle: string, pageUrl: string, severity: string, group: string, groupKey: string, label: string, fix: string, action: null|array{kind: string, url: string}, chatPrompt: string, thumbnail: string}
     */
    private function present(array $row, string $title): array
    {
        $description = $this->sources[$row['kind']]->describe($row, $title);
        $action = $this->actions->forItem($row);
        $fix = null === $action ? ($this->fixTargets->kindOf($row) ?? '') : '';

        return [
            'uid' => $row['uid'],
            'kind' => $row['kind'],
            'pageId' => $row['page_uid'],
            'pageTitle' => $title,
            'pageUrl' => $this->targetUrl($row),
            'severity' => $row['severity'],
            'group' => $description['group'],
            'groupKey' => $this->sources[$row['kind']] instanceof CustomRulesSource
                ? $this->sources[$row['kind']]->groupKeyOf($row)
                : $row['kind'].':'.$description['group'],
            'label' => $description['label'] === $title ? '' : $description['label'],
            'fix' => $fix,
            'action' => $action,
            'chatPrompt' => null === $action && '' === $fix ? $this->chatPrompts->forItem($row, $title) : '',
            'thumbnail' => $this->thumbnailOf($row['record_table'], $row['record_uid']),
        ];
    }

    private function thumbnailOf(string $table, int $uid): string
    {
        $fileField = OpenItemFixTargets::IMAGE_TABLES[$table] ?? null;
        if (null === $fileField || $uid <= 0) {
            return '';
        }
        $fileUid = (int) (BackendUtility::getRecord($table, $uid, $fileField)[$fileField] ?? 0);

        try {
            $file = $fileUid > 0 ? $this->resourceFactory->getFileObject($fileUid) : null;
            if (null === $file || !str_starts_with($file->getMimeType(), 'image/') || !$file->checkActionPermission('read')) {
                return '';
            }

            return (string) $file->process(ProcessedFile::CONTEXT_IMAGEPREVIEW, ['width' => self::THUMBNAIL_SIZE, 'height' => self::THUMBNAIL_SIZE])->getPublicUrl();
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * @param array{page_uid: int, record_table: string, record_uid: int} $row
     */
    private function targetUrl(array $row): string
    {
        if ('' !== $row['record_table'] && $row['record_uid'] > 0) {
            return (string) $this->uriBuilder->buildUriFromRoute('record_edit', ['edit' => [$row['record_table'] => [$row['record_uid'] => 'edit']]]);
        }

        return (string) $this->uriBuilder->buildUriFromRoute('web_layout', ['id' => $row['page_uid']]);
    }

    /**
     * @param array{kind: string, item_key: string} $row
     */
    private function mayShowFromRule(array $row): bool
    {
        $source = $this->sources[$row['kind']] ?? null;
        $backendUser = $this->backendUserService->getBackendUser();

        return !$source instanceof CustomRulesSource || (null !== $backendUser && $source->mayShow($row, $backendUser));
    }

    private function canManageRules(): bool
    {
        $backendUser = $this->backendUserService->getBackendUser();

        return null !== $backendUser && $this->ruleAccess->mayManage($backendUser);
    }

    private function mayReadTable(string $table): bool
    {
        if ('' === $table) {
            return true;
        }
        $backendUser = $this->backendUserService->getBackendUser();

        return null !== $backendUser && ($backendUser->isAdmin() || $backendUser->check('tables_select', $table));
    }

    private function readablePageTitle(int $pageUid): ?string
    {
        if (!array_key_exists($pageUid, $this->pageTitles)) {
            $backendUser = $this->backendUserService->getBackendUser();
            $page = null === $backendUser ? false : BackendUtility::readPageAccess($pageUid, $backendUser->getPagePermsClause(Permission::PAGE_SHOW));
            $this->pageTitles[$pageUid] = is_array($page) ? (string) ($page['title'] ?? '') : null;
        }

        return $this->pageTitles[$pageUid];
    }
}
