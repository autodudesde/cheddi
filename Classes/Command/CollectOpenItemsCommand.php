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

namespace AutoDudes\Cheddi\Command;

use AutoDudes\Cheddi\Service\Chat\OpenItemCollector;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Locking\Exception\LockAcquireWouldBlockException;
use TYPO3\CMS\Core\Locking\LockFactory;
use TYPO3\CMS\Core\Locking\LockingStrategyInterface;

class CollectOpenItemsCommand extends Command
{
    public const NAME = 'cheddi:collect-open-items';

    private const LOCK_ID = 'cheddi_collect_open_items';

    private const LOCK_MODE = LockingStrategyInterface::LOCK_CAPABILITY_EXCLUSIVE | LockingStrategyInterface::LOCK_CAPABILITY_NOBLOCK;

    public function __construct(
        private readonly OpenItemCollector $collector,
        private readonly LockFactory $lockFactory,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $locker = $this->lockFactory->createLocker(self::LOCK_ID, self::LOCK_MODE);

        if (!$this->acquire($locker)) {
            $io->note('Another run is in progress. Nothing was collected.');

            return Command::SUCCESS;
        }

        try {
            $result = $this->collector->run();
        } finally {
            $locker->release();
        }

        $io->success(implode(', ', array_map(
            static fn (string $kind, int $count): string => sprintf('%s: %d', $kind, $count),
            array_keys($result),
            array_values($result),
        )));

        return Command::SUCCESS;
    }

    private function acquire(LockingStrategyInterface $locker): bool
    {
        try {
            return $locker->acquire(self::LOCK_MODE);
        } catch (LockAcquireWouldBlockException) {
            return false;
        }
    }
}
