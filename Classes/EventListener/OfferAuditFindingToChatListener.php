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

namespace AutoDudes\Cheddi\EventListener;

use AutoDudes\AiSuite\Events\AuditFindingActionsEvent;
use AutoDudes\AiSuite\Service\BackendUserService;
use AutoDudes\AiSuite\Service\LocalizationService;
use AutoDudes\Cheddi\Enumeration\CheddiPermissionEnumeration;
use AutoDudes\Cheddi\Service\Chat\EditorTaskService;
use TYPO3\CMS\Core\Page\PageRenderer;

final class OfferAuditFindingToChatListener
{
    public const ACTION = 'cheddi-fix';

    private const LABEL = 'LLL:EXT:cheddi/Resources/Private/Language/locallang.xlf:cheddi.audit.fix';
    private const ICON = 'actions-message';

    public function __construct(
        private readonly BackendUserService $backendUserService,
        private readonly LocalizationService $localizationService,
        private readonly EditorTaskService $taskService,
        private readonly PageRenderer $pageRenderer,
    ) {}

    public function __invoke(AuditFindingActionsEvent $event): void
    {
        if ($event->hasOwnFix() || !$this->backendUserService->checkPermissions(CheddiPermissionEnumeration::INTERFACE)) {
            return;
        }

        $issue = $event->getIssue();
        $event->addAction(
            self::ACTION,
            $this->localizationService->translate(self::LABEL),
            self::ICON,
            $this->taskService->auditFinding(
                $event->getPageId(),
                $event->getAuditLabel(),
                (string) ($issue['message'] ?? ''),
                (string) ($issue['evidence'] ?? ''),
            ),
        );
        $this->pageRenderer->loadJavaScriptModule('@autodudes/cheddi/audit-handover.js');
    }
}
