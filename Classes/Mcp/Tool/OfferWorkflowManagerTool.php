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

namespace AutoDudes\Cheddi\Mcp\Tool;

use AutoDudes\AiSuiteMcp\Mcp\Tool\ToolContext;
use Mcp\Types\CallToolResult;
use TYPO3\CMS\Backend\Module\ModuleProvider;
use TYPO3\CMS\Backend\Routing\UriBuilder;

final class OfferWorkflowManagerTool extends AbstractChatTool
{
    public const NAME = 'offerWorkflowManager';

    private const PERMISSION = 'tx_aisuite_features:enable_massaction_generation';
    private const MODULE = 'web_aisuite';
    private const ROUTE = 'web_aisuite.workflow';
    private const LABEL_PREFIX = 'LLL:EXT:cheddi/Resources/Private/Language/locallang.xlf:cheddi.workflowManager.';

    protected bool $readOnlyHint = true;

    public function __construct(
        ToolContext $mcpToolContext,
        private readonly ModuleProvider $moduleProvider,
        private readonly UriBuilder $uriBuilder,
    ) {
        parent::__construct($mcpToolContext);
    }

    public function getName(): string
    {
        return self::NAME;
    }

    public function getDescription(): string
    {
        return 'Put a button to the Workflow Manager under your answer. Use when the editor asks for a mass run.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass()];
    }

    protected function doExecute(array $params): CallToolResult
    {
        $backendUser = $this->backendUserService->getBackendUser();
        if (null === $backendUser
            || !$this->backendUserService->checkPermissions(self::PERMISSION)
            || !$this->moduleProvider->accessGranted(self::MODULE, $backendUser)) {
            return $this->textResult('This editor has no access to the Workflow Manager, so no button is shown. Tell them an administrator can grant it.');
        }

        return $this->structuredResult(
            'A button to the Workflow Manager is shown under your answer. Name the module in one sentence.',
            ['offeredTarget' => [
                'group' => $this->localizationService->translate(self::LABEL_PREFIX.'group'),
                'label' => $this->localizationService->translate(self::LABEL_PREFIX.'open'),
                'url' => (string) $this->uriBuilder->buildUriFromRoute(self::ROUTE),
            ]],
        );
    }
}
