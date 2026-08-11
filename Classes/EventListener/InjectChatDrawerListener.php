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

use AutoDudes\AiSuite\Service\BackendUserService;
use AutoDudes\AiSuite\Service\IconService;
use TYPO3\CMS\Backend\Controller\Event\AfterBackendPageRenderEvent;
use TYPO3\CMS\Core\Page\PageRenderer;

final class InjectChatDrawerListener
{
    public function __construct(
        private readonly BackendUserService $backendUserService,
        private readonly IconService $iconService,
        private readonly PageRenderer $pageRenderer,
    ) {}

    public function __invoke(AfterBackendPageRenderEvent $event): void
    {
        if (!$this->backendUserService->checkPermissions('tx_aisuite_features:enable_cheddi_interface')) {
            return;
        }

        $this->pageRenderer->addCssFile('EXT:cheddi/Resources/Public/Css/chat-drawer.css');
        $this->pageRenderer->addInlineLanguageLabelFile('EXT:cheddi/Resources/Private/Language/locallang.xlf');
        $this->pageRenderer->loadJavaScriptModule('@autodudes/cheddi/chat-drawer.js');

        $event->setContent($event->getContent().$this->mountPointMarkup());
    }

    private function mountPointMarkup(): string
    {
        return sprintf(
            '<div id="cheddi-drawer" data-cheddi-drawer data-cheddi-brand-icon="%s"></div>',
            htmlspecialchars($this->iconService->getPublicIconUrl('tx-aisuite-extension'), ENT_QUOTES | ENT_HTML5),
        );
    }
}
