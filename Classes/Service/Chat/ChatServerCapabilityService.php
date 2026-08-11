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

use AutoDudes\AiSuite\Service\SendRequestService;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\SingletonInterface;

class ChatServerCapabilityService implements SingletonInterface
{
    /**
     * @var null|array{rates: array<string, int>, byok: bool, apiKeyConfigKey: array<string, string>, webSearchApiKeyConfigKey: string}
     */
    private ?array $capabilities = null;

    public function __construct(
        private readonly SendRequestService $sendRequestService,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @return array{rates: array<string, int>, byok: bool, apiKeyConfigKey: array<string, string>, webSearchApiKeyConfigKey: string}
     */
    public function get(): array
    {
        if (null !== $this->capabilities) {
            return $this->capabilities;
        }

        $capabilities = ['rates' => [], 'byok' => false, 'apiKeyConfigKey' => [], 'webSearchApiKeyConfigKey' => ''];

        try {
            $answer = $this->sendRequestService->sendDataRequest('chatModelRates');
            if ('ChatModelRates' === $answer->getType()) {
                $data = $answer->getResponseData();
                $capabilities = [
                    'rates' => is_array($data['rates'] ?? null) ? $data['rates'] : [],
                    'byok' => true === ($data['byok'] ?? false),
                    'apiKeyConfigKey' => is_array($data['apiKeyConfigKey'] ?? null) ? $data['apiKeyConfigKey'] : [],
                    'webSearchApiKeyConfigKey' => (string) ($data['webSearchApiKeyConfigKey'] ?? ''),
                ];
            }
        } catch (\Throwable $e) {
            $this->logger->warning('ChEddi: could not fetch chat server capabilities', [
                'exception' => $e->getMessage(),
            ]);
        }

        return $this->capabilities = $capabilities;
    }
}
