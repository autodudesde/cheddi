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

namespace AutoDudes\Cheddi\Domain\Repository;

use AutoDudes\AiSuite\Domain\Repository\AbstractRepository;
use AutoDudes\AiSuite\Service\WorkspaceContextService;
use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Core\Database\ConnectionPool;

class SchedulerTaskRepository extends AbstractRepository
{
    public const TABLE = 'tx_scheduler_task';

    public function __construct(
        ConnectionPool $connectionPool,
        WorkspaceContextService $workspaceContextService,
        string $table = self::TABLE,
        string $sortBy = '',
    ) {
        parent::__construct($connectionPool, $workspaceContextService, $table, $sortBy);
    }

    public function hasActiveCommandTask(string $commandIdentifier): bool
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();

        $matchesCommand = [
            $queryBuilder->expr()->like(
                'serialized_task_object',
                $queryBuilder->createNamedParameter('%'.$queryBuilder->escapeLikeWildcards('"'.$commandIdentifier.'"').'%'),
            ),
        ];
        // v14 stores the command as the task type; v12/v13 only inside the serialized task
        if (isset($GLOBALS['TCA'][self::TABLE]['columns']['tasktype'])) {
            $matchesCommand[] = $queryBuilder->expr()->eq('tasktype', $queryBuilder->createNamedParameter($commandIdentifier));
        }

        return (int) $queryBuilder
            ->count('uid')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, ParameterType::INTEGER)),
                $queryBuilder->expr()->eq('disable', $queryBuilder->createNamedParameter(0, ParameterType::INTEGER)),
                $queryBuilder->expr()->or(...$matchesCommand),
            )
            ->executeQuery()
            ->fetchOne() > 0
        ;
    }
}
