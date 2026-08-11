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

use AutoDudes\AiSuite\Events\CollectUsageStatisticsEvent;
use AutoDudes\AiSuite\Service\BackendUserService;
use AutoDudes\AiSuite\Service\SendRequestService;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

final class CollectChatStatisticsListener
{
    public function __construct(
        private readonly SendRequestService $requestService,
        private readonly BackendUserService $backendUserService,
    ) {}

    public function __invoke(CollectUsageStatisticsEvent $event): void
    {
        if (!$this->backendUserService->checkPermissions('tx_aisuite_features:enable_cheddi_interface')) {
            return;
        }

        $requestedMonth = $event->getRequest()->getQueryParams()['month'] ?? null;
        $additionalData = is_string($requestedMonth) && '' !== $requestedMonth ? ['month' => $requestedMonth] : [];

        try {
            $answer = $this->requestService->sendDataRequest('chatUsageStats', $additionalData);
        } catch (\Throwable) {
            return;
        }
        if ('ChatUsageStats' !== $answer->getType()) {
            return;
        }

        $data = $answer->getResponseData();
        $byModel = is_array($data['byModel'] ?? null) ? $data['byModel'] : [];

        $heading = $this->translate('heading', 'ChEddi — Chat Assistant');

        if ([] === $byModel) {
            $event->addGroup($heading, [[
                'identifier' => 'cheddi-empty',
                'title' => $this->translate('emptyTitle', 'ChEddi usage'),
                'labels' => [],
                'datasets' => [],
                'emptyText' => $this->translate('emptyText', 'No ChEddi usage recorded for the selected month.'),
            ]]);

            return;
        }

        $creditsLabel = $this->translate('creditsLabel', 'Credits');
        $event->addGroup($heading, [[
            'identifier' => 'cheddi-credits-by-model',
            'title' => $this->translate('byModelTitle', 'Credits by model'),
            'type' => 'pie',
            'labels' => array_map(static fn (array $r): string => (string) ($r['model'] ?? ''), $byModel),
            'datasets' => [[
                'label' => $creditsLabel,
                'data' => array_map(static fn (array $r): int => (int) ($r['credits'] ?? 0), $byModel),
            ]],
        ]]);
    }

    private function translate(string $key, string $fallback): string
    {
        try {
            $label = LocalizationUtility::translate(
                'LLL:EXT:cheddi/Resources/Private/Language/locallang.xlf:cheddi.statistics.'.$key,
            );
        } catch (\Throwable) {
            $label = null;
        }

        return is_string($label) && '' !== $label ? $label : $fallback;
    }
}
