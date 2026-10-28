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

use AutoDudes\Cheddi\Service\Chat\ChatSessionAutoDeleter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Locking\Exception\LockAcquireWouldBlockException;
use TYPO3\CMS\Core\Locking\LockFactory;
use TYPO3\CMS\Core\Locking\LockingStrategyInterface;

class AutoDeleteChatSessionsCommand extends Command
{
    public const NAME = 'cheddi:auto-delete-sessions';

    private const LOCK_ID = 'cheddi_auto_delete_sessions';

    private const LOCK_MODE = LockingStrategyInterface::LOCK_CAPABILITY_EXCLUSIVE | LockingStrategyInterface::LOCK_CAPABILITY_NOBLOCK;

    public function __construct(
        private readonly ChatSessionAutoDeleter $autoDeleter,
        private readonly LockFactory $lockFactory,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Chat sessions — auto-delete');

        $locker = $this->lockFactory->createLocker(self::LOCK_ID, self::LOCK_MODE);

        if (!$this->acquire($locker)) {
            $io->note('Another auto-delete run is in progress. Nothing was deleted.');

            return Command::SUCCESS;
        }

        try {
            $result = $this->autoDeleter->run();
        } finally {
            $locker->release();
        }

        $io->success(sprintf(
            'Soft-deleted: %d session(s). Hard-deleted: %d session(s).',
            $result['softDeleted'],
            $result['hardDeleted'],
        ));

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
