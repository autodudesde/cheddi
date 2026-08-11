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

use AutoDudes\Cheddi\Domain\Repository\ChatChangeRepository;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Workspaces\Event\AfterRecordPublishedEvent;

class WorkspacePublishListener
{
    public function __construct(
        private readonly ChatChangeRepository $changeRepository,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(AfterRecordPublishedEvent $event): void
    {
        try {
            $this->changeRepository->removeByRecord($event->getTable(), $event->getRecordId());
        } catch (\Throwable $e) {
            $this->logger->warning('ChEddi: could not clear the change entry of a published record', [
                'table' => $event->getTable(),
                'uid' => $event->getRecordId(),
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
