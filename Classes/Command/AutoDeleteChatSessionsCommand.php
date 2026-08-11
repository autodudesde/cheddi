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
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'cheddi:auto-delete-sessions',
    description: 'Soft-deletes chat sessions older than chatSessionLifetimeDays and hard-deletes soft-deleted sessions older than 7 days.',
)]
class AutoDeleteChatSessionsCommand extends Command
{
    public function __construct(
        private readonly ChatSessionAutoDeleter $autoDeleter,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Chat sessions — auto-delete');

        $result = $this->autoDeleter->run();

        $io->success(sprintf(
            'Soft-deleted: %d session(s). Hard-deleted: %d session(s).',
            $result['softDeleted'],
            $result['hardDeleted'],
        ));

        return Command::SUCCESS;
    }
}
