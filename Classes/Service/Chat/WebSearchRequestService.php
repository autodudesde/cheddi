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
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\ConnectException;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class WebSearchRequestService
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
     * @return array{summary: string, sources: list<array{title: string, url: string, snippet: string}>, isError: bool}
     */
    public function search(string $query, int $maxSearches = 5): array
    {
        $extConf = $this->settingsFactory->mergeExtConfAndUserGroupSettings();

        if ('' === trim((string) ($extConf['aiSuiteApiKey'] ?? ''))) {
            $this->logger->info('Web search skipped: no AI Suite API key configured');

            return $this->errorResult('apiKeyMissing');
        }

        $endpoint = ((string) ($extConf['aiSuiteServer'] ?? '')).'api/webSearch';
        $options = [
            'headers' => [
                'Authorization' => 'Bearer '.((string) ($extConf['aiSuiteApiKey'] ?? '')),
                'X-AiSuite-Source' => 'chat',
            ],
            'form_params' => [
                'query' => $query,
                'maxSearches' => $maxSearches,
                'keys' => $this->modelService->fetchKeysByModelType($extConf, ['chat']),
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
            $this->logger->error('Web search request failed', ['statusCode' => $statusCode, 'body' => substr($body, 0, 512)]);

            return $this->errorResult('web-search-server-http-'.$statusCode);
        } catch (\Throwable $e) {
            $this->logger->error('Web search request failed', ['exception' => $e->getMessage()]);

            return $this->errorResult('web-search-server-unavailable');
        }

        /** @var mixed $decoded */
        $decoded = json_decode((string) $response->getBody(), true);
        if (!is_array($decoded)) {
            $this->logger->error('Web search response was not JSON');

            return $this->errorResult('web-search-invalid-response');
        }

        $body = is_array($decoded['body'] ?? null) ? $decoded['body'] : [];
        if ('Error' === ($decoded['type'] ?? '') || isset($body['errorType'])) {
            $this->logger->warning('Web search returned an error', ['errorType' => $body['errorType'] ?? '']);

            return $this->errorResult((string) ($body['errorType'] ?? 'web-search-error'));
        }

        return [
            'summary' => (string) ($body['summary'] ?? ''),
            'sources' => $this->normalizeSources($body['sources'] ?? null),
            'isError' => false,
        ];
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
                $this->logger->warning('Web search 5xx — retrying once', ['attempt' => $attempt, 'statusCode' => $statusCode]);
                $this->sleepBackoff();
            } catch (ConnectException $e) {
                if ($attempt >= self::RETRY_MAX_ATTEMPTS) {
                    throw $e;
                }
                $this->logger->warning('Web search connection failed — retrying once', ['attempt' => $attempt, 'exception' => $e->getMessage()]);
                $this->sleepBackoff();
            }
        }
    }

    /**
     * @return list<array{title: string, url: string, snippet: string}>
     */
    private function normalizeSources(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $sources = [];
        foreach ($raw as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $url = trim((string) ($entry['url'] ?? ''));
            if ('' === $url) {
                continue;
            }
            $sources[] = [
                'title' => (string) ($entry['title'] ?? $url),
                'url' => $url,
                'snippet' => (string) ($entry['snippet'] ?? ''),
            ];
        }

        return $sources;
    }

    /**
     * @return array{summary: string, sources: list<array{title: string, url: string, snippet: string}>, isError: bool}
     */
    private function errorResult(string $reason): array
    {
        return ['summary' => $reason, 'sources' => [], 'isError' => true];
    }
}
