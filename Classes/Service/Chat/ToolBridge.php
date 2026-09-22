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
use AutoDudes\AiSuite\Service\WorkspaceContextService;
use AutoDudes\AiSuiteMcp\Mcp\McpUserContext;
use AutoDudes\AiSuiteMcp\Mcp\Service\PermissionService;
use AutoDudes\AiSuiteMcp\Mcp\Tool\ToolAccessContext;
use AutoDudes\AiSuiteMcp\Mcp\Tool\ToolGateway;
use AutoDudes\AiSuiteMcp\Mcp\Tool\ToolInterface;
use AutoDudes\AiSuiteMcp\Mcp\Tool\ToolRegistry;
use AutoDudes\AiSuiteMcp\Mcp\Tool\Translation\SelfTranslatingToolInterface;
use AutoDudes\Cheddi\Domain\Enum\Severity;
use AutoDudes\Cheddi\Domain\Model\Dto\ChatToolContext;
use AutoDudes\Cheddi\Mcp\Tool\ReadWebPageTool;
use AutoDudes\Cheddi\Mcp\Tool\SearchWebTool;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

class ToolBridge
{
    public const CHAT_TOOL_TAG = 'aisuite.cheddi.tool';

    /**
     * @var list<string>
     */
    private const EXCLUDED_TOOLS = [
        'readServerInfo',
        'compareWithLive',
        'uploadMedia',
        'batchGenerateMetadata',
        'batchGenerateFileMetadata',
        'batchTranslatePage',
        'batchTranslateFileMetadata',
        'readTaskStatus',
        'readTaskResults',
        'applyTaskResults',
    ];

    private const BILLED_AI_SCOPES = ['mcp:generate', 'mcp:translate', 'mcp:image', 'mcp:workflow'];

    /**
     * @var null|list<string>
     */
    private ?array $cachedAvailableScopes = null;

    /**
     * @var array<string, ToolInterface>
     */
    private array $chatTools = [];

    /**
     * @param iterable<ToolInterface> $chatTools
     */
    public function __construct(
        private readonly ToolRegistry $toolRegistry,
        private readonly ToolGateway $toolGateway,
        private readonly PermissionService $permissionService,
        private readonly ToolPolicyResolver $policyResolver,
        private readonly McpUserContext $userContext,
        private readonly BackendUserService $backendUserService,
        private readonly WorkspaceContextService $workspaceContextService,
        private readonly LoggerInterface $logger,
        private readonly ChatOrientationService $orientationService,
        private readonly ChatSettingsService $chatSettings,
        private readonly WebResearchPolicy $webResearchPolicy,
        #[AutowireIterator(self::CHAT_TOOL_TAG)]
        iterable $chatTools = [],
    ) {
        foreach ($chatTools as $chatTool) {
            $this->chatTools[$chatTool->getName()] = $chatTool;
        }
    }

    /**
     * @return list<array{name: string, description: string, inputSchema: array<string, mixed>}>
     */
    public function getAvailableToolDefinitions(string $model = ''): array
    {
        $this->chatSettings->applyToMcpSurface();

        $excluded = $this->excludedTools();
        $result = [];
        foreach ($this->toolGateway->listTools($this->accessContext(), $this->availableChatTools($model)) as $tool) {
            if (in_array($tool->getName(), $excluded, true)) {
                continue;
            }
            $result[] = [
                'name' => $tool->getName(),
                'description' => $tool->getDescription(),
                'inputSchema' => $tool->getSchema(),
            ];
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array{content: string, isError: bool, structured: null|array<string, mixed>}
     */
    public function execute(string $toolName, array $arguments, ChatToolContext $context): array
    {
        $this->chatSettings->applyToMcpSurface();

        $this->userContext->setInlineBackendLinks(false);
        $this->userContext->setReportFoundRecords(true);

        $chatTools = $this->availableChatTools($context->model);
        if (isset($chatTools[$toolName])) {
            $result = $this->toolGateway->callTool($toolName, $arguments, $this->accessContext(), $chatTools);

            return [
                'content' => $this->toolGateway->textOf($result),
                'isError' => (bool) $result->isError,
                'structured' => $result->structuredContent,
            ];
        }

        if (null === $this->toolRegistry->getTool($toolName)) {
            return [
                'content' => 'Tool "'.$toolName.'" is not registered.',
                'isError' => true,
                'structured' => null,
            ];
        }

        if (in_array($toolName, $this->excludedTools(), true)) {
            return [
                'content' => 'Tool "'.$toolName.'" is not available in this chat. '
                    .'Bulk runs over a folder or a page subtree belong in the Workflow Manager module; '
                    .'tell the editor to open it from the AI Suite module instead.',
                'isError' => true,
                'structured' => null,
            ];
        }

        try {
            $this->ensureUserContextInitialized($context);
        } catch (\Throwable $e) {
            $this->logger->warning('ChEddi: tool call aborted, the write workspace could not be established', [
                'tool' => $toolName,
                'error' => $e->getMessage(),
            ]);

            return [
                'content' => 'Tool "'.$toolName.'" was not executed: '.$e->getMessage(),
                'isError' => true,
                'structured' => null,
            ];
        }

        $result = $this->toolGateway->callTool($toolName, $arguments, $this->accessContext());
        $text = $this->toolGateway->textOf($result);

        return [
            'content' => $text,
            'isError' => (bool) $result->isError,
            'structured' => $result->structuredContent,
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function resolvePolicy(string $toolName, array $arguments = []): Severity
    {
        $tool = $this->chatTools[$toolName] ?? $this->toolRegistry->getTool($toolName);
        if (null === $tool) {
            return Severity::Write;
        }

        if ($this->isModelDiscoveryCall($tool, $arguments)) {
            return Severity::ReadOnly;
        }

        return $this->policyResolver->resolve($tool);
    }

    public function creditCost(string $toolName): ?int
    {
        $tool = $this->chatTools[$toolName] ?? $this->toolRegistry->getTool($toolName);

        return $tool?->getCreditCost();
    }

    public function webResearchAvailable(string $model = ''): bool
    {
        return $this->webResearchPolicy->isBrokeredSearchAllowed()
            && !$this->webResearchPolicy->isNativeSearchModel($model);
    }

    public function webPageReadingAvailable(): bool
    {
        return $this->webResearchPolicy->isPageReadingAllowed();
    }

    public function nativeWebResearchAvailable(string $model): bool
    {
        return $this->webResearchPolicy->isNativeSearchAllowed($model);
    }

    /**
     * @return array<string, mixed>
     */
    public function webResearchTurnConfiguration(string $model): array
    {
        return $this->webResearchPolicy->turnConfiguration($model);
    }

    /**
     * @return list<string>
     */
    private function excludedTools(): array
    {
        return array_values(array_unique([...self::EXCLUDED_TOOLS, ...$this->chatSettings->getExcludedTools()]));
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function isModelDiscoveryCall(ToolInterface $tool, array $arguments): bool
    {
        if (!in_array($tool->getRequiredScope(), self::BILLED_AI_SCOPES, true)) {
            return false;
        }

        if ($tool instanceof SelfTranslatingToolInterface) {
            return false;
        }

        if (!isset($tool->getSchema()['properties']['model'])) {
            return false;
        }

        $model = $arguments['model'] ?? null;

        return !is_string($model) || '' === trim($model);
    }

    /**
     * @return list<string>
     */
    private function availableScopes(): array
    {
        return $this->cachedAvailableScopes ??= $this->permissionService->getAvailableScopes();
    }

    /**
     * @return array<string, ToolInterface>
     */
    private function availableChatTools(string $model = ''): array
    {
        $blocked = [];
        if (!$this->webResearchAvailable($model)) {
            $blocked[] = SearchWebTool::NAME;
        }
        if (!$this->webPageReadingAvailable()) {
            $blocked[] = ReadWebPageTool::NAME;
        }
        if ([] === $blocked) {
            return $this->chatTools;
        }

        return array_filter(
            $this->chatTools,
            static fn (string $name): bool => !in_array($name, $blocked, true),
            ARRAY_FILTER_USE_KEY,
        );
    }

    private function accessContext(): ToolAccessContext
    {
        return new ToolAccessContext($this->availableScopes(), ToolAccessContext::VIA_CHEDDI);
    }

    private function ensureUserContextInitialized(ChatToolContext $context): void
    {
        if (null === $this->userContext->getServerRequest()) {
            $this->userContext->setServerRequest($context->request);
        }
        if ($this->userContext->isInitialized()) {
            return;
        }
        $backendUser = $this->backendUserService->getBackendUser();
        if (null === $backendUser) {
            return;
        }
        $this->userContext->initialize(
            beUserUid: (int) ($backendUser->user['uid'] ?? 0),
            scopes: $this->availableScopes(),
            clientId: 'cheddi',
            tokenId: '',
        );

        if ('' !== $context->sessionUuid) {
            $this->userContext->setSessionKey('cheddi:'.$context->sessionUuid);
        }

        $workspaceId = $this->orientationService->resolveWorkspaceId($backendUser);

        if (false === $this->workspaceContextService->applyWorkspace($backendUser, $workspaceId)) {
            throw new \RuntimeException(sprintf('The backend user has no access to workspace %d.', $workspaceId));
        }
    }
}
