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
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Core\Database\ConnectionPool;

class ChatChangeRepository extends AbstractRepository
{
    public const TABLE = 'tx_cheddi_change';

    public function __construct(
        ConnectionPool $connectionPool,
        WorkspaceContextService $workspaceContextService,
        string $table = self::TABLE,
        string $sortBy = 'crdate',
    ) {
        parent::__construct($connectionPool, $workspaceContextService, $table, $sortBy);
    }

    /**
     * @throws Exception
     */
    public function addChange(
        int $session,
        string $table,
        int $recordUid,
        int $workspaceRecordUid,
        int $workspace,
        int $pageId,
        string $action,
        int $timestamp,
    ): void {
        $this->connectionPool->getConnectionForTable($this->table)->insert(
            $this->table,
            [
                'pid' => 0,
                'tstamp' => $timestamp,
                'crdate' => $timestamp,
                'session' => $session,
                'tablename' => $table,
                'record_uid' => $recordUid,
                'workspace_record_uid' => $workspaceRecordUid,
                'workspace' => $workspace,
                'page_id' => $pageId,
                'action' => $action,
            ],
        );
    }

    /**
     * @return list<array<string, mixed>>
     *
     * @throws Exception
     * @throws \Doctrine\DBAL\Driver\Exception
     */
    public function findBySession(int $session): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($this->table);
        $queryBuilder->getRestrictions()->removeAll();

        return array_values(
            $queryBuilder
                ->select('*')
                ->from($this->table)
                ->where(
                    $queryBuilder->expr()->eq('session', $queryBuilder->createNamedParameter($session, ParameterType::INTEGER)),
                )
                ->orderBy('crdate', 'ASC')
                ->addOrderBy('uid', 'ASC')
                ->executeQuery()
                ->fetchAllAssociative(),
        );
    }

    /**
     * @throws Exception
     */
    public function removeByRecord(string $table, int $recordUid): void
    {
        $this->connectionPool->getConnectionForTable($this->table)->delete(
            $this->table,
            ['tablename' => $table, 'record_uid' => $recordUid],
        );
    }

    /**
     * @throws Exception
     */
    public function removeBySession(int $session): void
    {
        $this->connectionPool->getConnectionForTable($this->table)->delete(
            $this->table,
            ['session' => $session],
        );
    }

    /**
     * @throws Exception
     * @throws \Doctrine\DBAL\Driver\Exception
     */
    public function hasChange(int $session, string $table, int $recordUid): bool
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($this->table);
        $queryBuilder->getRestrictions()->removeAll();

        $count = $queryBuilder
            ->count('uid')
            ->from($this->table)
            ->where(
                $queryBuilder->expr()->eq('session', $queryBuilder->createNamedParameter($session, ParameterType::INTEGER)),
                $queryBuilder->expr()->eq('tablename', $queryBuilder->createNamedParameter($table)),
                $queryBuilder->expr()->eq('record_uid', $queryBuilder->createNamedParameter($recordUid, ParameterType::INTEGER)),
            )
            ->executeQuery()
            ->fetchOne()
        ;

        return (int) $count > 0;
    }
}
