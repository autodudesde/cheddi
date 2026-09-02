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

use AutoDudes\AiSuite\Domain\Repository\RequestsRepository;
use AutoDudes\AiSuite\Factory\SettingsFactory;
use AutoDudes\AiSuite\Service\SendRequestService;
use Psr\Log\LoggerInterface;

class ChatCreditsService
{
    public const LOW_BALANCE_THRESHOLD = 50;

    public function __construct(
        private readonly RequestsRepository $requestsRepository,
        private readonly SettingsFactory $settingsFactory,
        private readonly SendRequestService $sendRequestService,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @return array{pack: null|int, planUsed: null|int, planTotal: null|int}
     */
    public function refreshCredits(): array
    {
        try {
            // Writes the row itself; the chat turn does not, because it bypasses SendRequestService.
            $this->sendRequestService->sendDataRequest('getRequestsState');
        } catch (\Throwable $e) {
            $this->logger->warning('ChEddi: could not refresh the credit state from the server', [
                'error' => $e->getMessage(),
            ]);
        }

        return $this->getCredits();
    }

    /**
     * @return array{pack: null|int, planUsed: null|int, planTotal: null|int, low: bool}
     */
    public function getCredits(): array
    {
        $empty = ['pack' => null, 'planUsed' => null, 'planTotal' => null, 'low' => false];

        try {
            $extConf = $this->settingsFactory->mergeExtConfAndUserGroupSettings();
            $apiKey = trim((string) ($extConf['aiSuiteApiKey'] ?? ''));
            if ('' === $apiKey) {
                return $empty;
            }
            $row = $this->requestsRepository->findEntryByApiKey($apiKey);
        } catch (\Throwable $e) {
            $this->logger->warning('ChEddi: could not read the credit state', ['error' => $e->getMessage()]);

            return $empty;
        }

        if ([] === $row) {
            return $empty;
        }

        // `abo_requests` holds what is left, so the used figure is derived - as in the toolbar.
        $pack = (int) ($row['paid_requests'] ?? 0);
        $planTotal = (int) ($row['model_type'] ?? 0);
        if ($planTotal <= 0) {
            return self::withLowFlag(['pack' => max(0, $pack), 'planUsed' => null, 'planTotal' => null]);
        }

        return self::withLowFlag([
            'pack' => max(0, $pack),
            'planUsed' => max(0, $planTotal - (int) ($row['abo_requests'] ?? 0)),
            'planTotal' => $planTotal,
        ]);
    }

    /**
     * @param array{pack: null|int, planUsed: null|int, planTotal: null|int} $credits
     *
     * @return array{pack: null|int, planUsed: null|int, planTotal: null|int, low: bool}
     */
    private static function withLowFlag(array $credits): array
    {
        $planHasRoom = null !== $credits['planTotal']
            && $credits['planTotal'] - ($credits['planUsed'] ?? 0) > 0;

        return [
            ...$credits,
            'low' => null !== $credits['pack']
                && $credits['pack'] < self::LOW_BALANCE_THRESHOLD
                && !$planHasRoom,
        ];
    }
}
