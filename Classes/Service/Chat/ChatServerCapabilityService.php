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
use AutoDudes\AiSuite\Service\SendRequestService;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\SingletonInterface;

class ChatServerCapabilityService implements SingletonInterface
{
    private const CACHE_PREFIX = 'cheddi_capabilities_';

    private const LIFETIME_SECONDS = 900;

    /**
     * @var array{rates: array<string, int>, byok: bool, apiKeyConfigKey: array<string, string>, webSearchApiKeyConfigKey: string, nativeWebSearchModels: list<string>, gdprWebSearchProvider: string, gdprWebSearchCountries: list<string>, gdprWebSearchApiKeyConfigKey: string}
     */
    private const NEUTRAL_DEFAULTS = [
        'rates' => [],
        'byok' => false,
        'apiKeyConfigKey' => [],
        'webSearchApiKeyConfigKey' => '',
        'nativeWebSearchModels' => [],
        'gdprWebSearchProvider' => '',
        'gdprWebSearchCountries' => [],
        'gdprWebSearchApiKeyConfigKey' => '',
    ];

    /**
     * @var null|array{rates: array<string, int>, byok: bool, apiKeyConfigKey: array<string, string>, webSearchApiKeyConfigKey: string, nativeWebSearchModels: list<string>, gdprWebSearchProvider: string, gdprWebSearchCountries: list<string>, gdprWebSearchApiKeyConfigKey: string}
     */
    private ?array $capabilities = null;

    public function __construct(
        private readonly SendRequestService $sendRequestService,
        private readonly SettingsFactory $settingsFactory,
        #[Autowire(service: 'cache.hash')]
        private readonly FrontendInterface $cache,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @return array{rates: array<string, int>, byok: bool, apiKeyConfigKey: array<string, string>, webSearchApiKeyConfigKey: string, nativeWebSearchModels: list<string>, gdprWebSearchProvider: string, gdprWebSearchCountries: list<string>, gdprWebSearchApiKeyConfigKey: string}
     */
    public function get(): array
    {
        if (null !== $this->capabilities) {
            return $this->capabilities;
        }

        $cacheKey = $this->cacheKey();
        if (null !== $cacheKey) {
            $cached = $this->cache->get($cacheKey);
            if (is_array($cached)) {
                // @phpstan-ignore-next-line the cache frontend cannot describe what it stored
                return $this->capabilities = $cached;
            }
        }

        $answered = $this->ask();
        if (null === $answered) {
            return $this->capabilities = self::NEUTRAL_DEFAULTS;
        }

        if (null !== $cacheKey) {
            $this->cache->set($cacheKey, $answered, [], self::LIFETIME_SECONDS);
        }

        return $this->capabilities = $answered;
    }

    /**
     * @return null|array{rates: array<string, int>, byok: bool, apiKeyConfigKey: array<string, string>, webSearchApiKeyConfigKey: string, nativeWebSearchModels: list<string>, gdprWebSearchProvider: string, gdprWebSearchCountries: list<string>, gdprWebSearchApiKeyConfigKey: string}
     */
    private function ask(): ?array
    {
        try {
            $answer = $this->sendRequestService->sendDataRequest('chatModelRates');
            if ('ChatModelRates' !== $answer->getType()) {
                return null;
            }
            $data = $answer->getResponseData();

            return [
                'rates' => is_array($data['rates'] ?? null) ? $data['rates'] : [],
                'byok' => true === ($data['byok'] ?? false),
                'apiKeyConfigKey' => is_array($data['apiKeyConfigKey'] ?? null) ? $data['apiKeyConfigKey'] : [],
                'webSearchApiKeyConfigKey' => (string) ($data['webSearchApiKeyConfigKey'] ?? ''),
                'nativeWebSearchModels' => array_values(array_filter(
                    is_array($data['nativeWebSearchModels'] ?? null) ? $data['nativeWebSearchModels'] : [],
                    'is_string',
                )),
                'gdprWebSearchProvider' => (string) ($data['gdprWebSearchProvider'] ?? ''),
                'gdprWebSearchCountries' => array_values(array_filter(
                    is_array($data['gdprWebSearchCountries'] ?? null) ? $data['gdprWebSearchCountries'] : [],
                    'is_string',
                )),
                'gdprWebSearchApiKeyConfigKey' => (string) ($data['gdprWebSearchApiKeyConfigKey'] ?? ''),
            ];
        } catch (\Throwable $e) {
            $this->logger->warning('ChEddi: could not fetch chat server capabilities', [
                'exception' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function cacheKey(): ?string
    {
        try {
            $extConf = $this->settingsFactory->mergeExtConfAndUserGroupSettings();
        } catch (\Throwable) {
            return null;
        }

        $apiKey = trim((string) ($extConf['aiSuiteApiKey'] ?? ''));
        if ('' === $apiKey) {
            return null;
        }

        return self::CACHE_PREFIX.hash('sha256', $apiKey.'|'.trim((string) ($extConf['aiSuiteServer'] ?? '')));
    }
}
