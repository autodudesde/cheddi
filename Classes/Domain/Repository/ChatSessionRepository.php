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
use AutoDudes\Cheddi\Domain\Model\ChatSession;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class ChatSessionRepository extends AbstractRepository
{
    public const TABLE = 'tx_cheddi_session';

    public function __construct(
        ConnectionPool $connectionPool,
        WorkspaceContextService $workspaceContextService,
        string $table = self::TABLE,
        string $sortBy = 'last_activity',
    ) {
        parent::__construct($connectionPool, $workspaceContextService, $table, $sortBy);
    }

    /**
     * @throws Exception
     * @throws \Doctrine\DBAL\Driver\Exception
     */
    public function findByUuidForUser(string $sessionUuid, int $beUser): ?ChatSession
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($this->table);
        $queryBuilder->getRestrictions()->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class))
        ;

        $row = $queryBuilder
            ->select('*')
            ->from($this->table)
            ->where(
                $queryBuilder->expr()->eq('session_uuid', $queryBuilder->createNamedParameter($sessionUuid)),
                $queryBuilder->expr()->eq('be_user', $queryBuilder->createNamedParameter($beUser, ParameterType::INTEGER)),
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative()
        ;

        return false === $row ? null : ChatSession::fromRow($row);
    }

    /**
     * @return list<ChatSession>
     *
     * @throws Exception
     * @throws \Doctrine\DBAL\Driver\Exception
     */
    public function findAllForUser(int $beUser): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($this->table);
        $queryBuilder->getRestrictions()->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class))
        ;

        $rows = $queryBuilder
            ->select('*')
            ->from($this->table)
            ->where(
                $queryBuilder->expr()->eq('be_user', $queryBuilder->createNamedParameter($beUser, ParameterType::INTEGER)),
            )
            ->orderBy('last_activity', 'DESC')
            ->executeQuery()
            ->fetchAllAssociative()
        ;

        return array_map(static fn (array $row): ChatSession => ChatSession::fromRow($row), $rows);
    }

    public function create(string $sessionUuid, int $beUser, string $model, string $title = ''): int
    {
        $now = time();
        $connection = $this->connectionPool->getConnectionForTable($this->table);
        $connection->insert($this->table, [
            'session_uuid' => $sessionUuid,
            'be_user' => $beUser,
            'model' => $model,
            'title' => $title,
            'last_activity' => $now,
            'crdate' => $now,
            'tstamp' => $now,
            'deleted' => 0,
        ]);

        return (int) $connection->lastInsertId();
    }

    public function touchActivity(int $sessionUid): void
    {
        $now = time();
        $this->connectionPool->getConnectionForTable($this->table)
            ->update(
                $this->table,
                ['last_activity' => $now, 'tstamp' => $now],
                ['uid' => $sessionUid],
            )
        ;
    }

    public function updateTitle(int $sessionUid, string $title): void
    {
        $this->connectionPool->getConnectionForTable($this->table)
            ->update(
                $this->table,
                ['title' => $title, 'tstamp' => time()],
                ['uid' => $sessionUid],
            )
        ;
    }

    public function softDelete(int $sessionUid): void
    {
        $this->connectionPool->getConnectionForTable($this->table)
            ->update(
                $this->table,
                ['deleted' => 1, 'tstamp' => time()],
                ['uid' => $sessionUid],
            )
        ;
    }

    /**
     * @return list<int>
     *
     * @throws Exception
     * @throws \Doctrine\DBAL\Driver\Exception
     */
    public function findActiveExpiredUids(int $cutoffLastActivity): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($this->table);
        $queryBuilder->getRestrictions()->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class))
        ;

        $rows = $queryBuilder
            ->select('uid')
            ->from($this->table)
            ->where(
                $queryBuilder->expr()->lt('last_activity', $queryBuilder->createNamedParameter($cutoffLastActivity, ParameterType::INTEGER)),
            )
            ->executeQuery()
            ->fetchAllAssociative()
        ;

        return array_map(static fn (array $row): int => (int) $row['uid'], $rows);
    }

    /**
     * @return list<int>
     *
     * @throws Exception
     * @throws \Doctrine\DBAL\Driver\Exception
     */
    public function findSoftDeletedUidsOlderThan(int $cutoffTstamp): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($this->table);
        $queryBuilder->getRestrictions()->removeAll();

        $rows = $queryBuilder
            ->select('uid')
            ->from($this->table)
            ->where(
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(1, ParameterType::INTEGER)),
                $queryBuilder->expr()->lt('tstamp', $queryBuilder->createNamedParameter($cutoffTstamp, ParameterType::INTEGER)),
            )
            ->executeQuery()
            ->fetchAllAssociative()
        ;

        return array_map(static fn (array $row): int => (int) $row['uid'], $rows);
    }

    public function hardDelete(int $sessionUid): void
    {
        $connection = $this->connectionPool->getConnectionForTable($this->table);
        $queryBuilder = $connection->createQueryBuilder();
        $queryBuilder
            ->delete($this->table)
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($sessionUid, ParameterType::INTEGER)))
            ->executeStatement()
        ;
    }
}
