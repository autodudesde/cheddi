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

use AutoDudes\AiSuite\Factory\SettingsFactory;
use AutoDudes\AiSuite\Service\ModelService;
use AutoDudes\Cheddi\Domain\Model\Dto\ChatTurnAnswer;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\ConnectException;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class ChatRequestService
{
    public const RETRY_BACKOFF_SECONDS = 2;
    public const RETRY_MAX_ATTEMPTS = 2;

    public function __construct(
        protected readonly RequestFactory $requestFactory,
        protected readonly SettingsFactory $settingsFactory,
        protected readonly LoggerInterface $logger,
        protected readonly GdprModelPolicy $gdprModelPolicy,
        protected readonly ModelService $modelService,
    ) {}

    /**
     * @param list<array{role: string, content: string, toolCalls?: list<array<string, mixed>>, toolCallId?: string, toolStatus?: string, providerItems?: string}> $messages
     * @param list<array{name: string, description: string, inputSchema: array<string, mixed>}>                                                                    $tools
     */
    public function executeTurn(
        string $model,
        array $messages,
        array $tools,
        string $systemContext,
    ): ChatTurnAnswer {
        $extConf = $this->settingsFactory->mergeExtConfAndUserGroupSettings();

        if ('' === trim((string) ($extConf['aiSuiteApiKey'] ?? ''))) {
            $this->logger->info('Chat turn skipped: no AI Suite API key configured');

            return $this->buildErrorAnswer('AI Suite API key missing', 'apiKeyMissing');
        }

        $endpoint = ((string) ($extConf['aiSuiteServer'] ?? '')).'api/chatTurn';
        $options = [
            'headers' => [
                'Authorization' => 'Bearer '.((string) ($extConf['aiSuiteApiKey'] ?? '')),
                'X-AiSuite-Source' => 'chat',
            ],
            'form_params' => [
                'model' => $model,
                'messages' => json_encode($messages),
                'tools' => json_encode($tools),
                'systemContext' => $systemContext,
                'keys' => $this->modelService->fetchKeysByModel($extConf, [$model]),
                'request_system_domain' => GeneralUtility::getIndpEnv('HTTP_HOST'),
                'typo3_version' => GeneralUtility::makeInstance(Typo3Version::class)->getMajorVersion(),
                'gdprOnly' => $this->gdprModelPolicy->isForced($extConf) ? '1' : '0',
            ],
        ];

        try {
            $response = $this->performWithRetry($endpoint, $options);
        } catch (BadResponseException $e) {
            $statusCode = $e->getResponse()->getStatusCode();
            $body = (string) $e->getResponse()->getBody();
            $this->logger->error(
                'Chat turn request failed',
                ['statusCode' => $statusCode, 'body' => substr($body, 0, 512)],
            );

            return $this->buildErrorAnswer(sprintf('chat-server-http-%d: %s', $statusCode, substr($body, 0, 256)));
        } catch (\Throwable $e) {
            $this->logger->error('Chat turn request failed', ['exception' => $e->getMessage()]);

            return $this->buildErrorAnswer('chat-server-unavailable: '.$e->getMessage());
        }

        $body = (string) $response->getBody();

        /** @var mixed $decoded */
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            $this->logger->error('Chat turn response was not JSON', ['body' => substr($body, 0, 256)]);

            return $this->buildErrorAnswer('chat-server-invalid-response');
        }

        return ChatTurnAnswer::fromResponseData($decoded);
    }

    protected function sleepBackoff(): void
    {
        sleep(self::RETRY_BACKOFF_SECONDS);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function performWithRetry(string $endpoint, array $options): ResponseInterface
    {
        $attempt = 0;
        while (true) {
            ++$attempt;

            try {
                return $this->requestFactory->request($endpoint, 'POST', $options);
            } catch (BadResponseException $e) {
                $statusCode = $e->getResponse()->getStatusCode();
                if ($statusCode < 500 || $attempt >= self::RETRY_MAX_ATTEMPTS) {
                    throw $e;
                }
                $this->logger->warning(
                    'Chat turn 5xx — retrying once',
                    ['attempt' => $attempt, 'statusCode' => $statusCode],
                );
                $this->sleepBackoff();
            } catch (ConnectException $e) {
                if ($attempt >= self::RETRY_MAX_ATTEMPTS) {
                    throw $e;
                }
                $this->logger->warning(
                    'Chat turn connection failed — retrying once',
                    ['attempt' => $attempt, 'exception' => $e->getMessage()],
                );
                $this->sleepBackoff();
            }
        }
    }

    private function buildErrorAnswer(string $message, ?string $chatErrorCode = null): ChatTurnAnswer
    {
        return ChatTurnAnswer::fromResponseData([
            'type' => ChatTurnAnswer::TYPE_ERROR,
            'body' => array_filter([
                'message' => $message,
                'chatErrorCode' => $chatErrorCode,
            ], static fn (?string $value): bool => null !== $value),
        ]);
    }
}
