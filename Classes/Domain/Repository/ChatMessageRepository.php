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
use AutoDudes\Cheddi\Domain\Model\ChatMessage;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

class ChatMessageRepository extends AbstractRepository
{
    public const TABLE = 'tx_cheddi_message';

    public function __construct(
        ConnectionPool $connectionPool,
        WorkspaceContextService $workspaceContextService,
        string $table = self::TABLE,
        string $sortBy = 'sort',
    ) {
        parent::__construct($connectionPool, $workspaceContextService, $table, $sortBy);
    }

    /**
     * @return list<ChatMessage>
     *
     * @throws Exception
     * @throws \Doctrine\DBAL\Driver\Exception
     */
    public function findBySession(int $sessionUid): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($this->table);
        $queryBuilder->getRestrictions()->removeAll();

        $rows = $queryBuilder
            ->select('*')
            ->from($this->table)
            ->where($queryBuilder->expr()->eq('session', $queryBuilder->createNamedParameter($sessionUid, ParameterType::INTEGER)))
            ->orderBy('sort', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative()
        ;

        return array_map(static fn (array $row): ChatMessage => ChatMessage::fromRow($row), $rows);
    }

    /**
     * @return list<int>
     *
     * @throws Exception
     * @throws \Doctrine\DBAL\Driver\Exception
     */
    public function findAttachmentUidsBySession(int $sessionUid): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($this->table);
        $queryBuilder->getRestrictions()->removeAll();

        $rows = $queryBuilder
            ->select('attachments')
            ->from($this->table)
            ->where(
                $queryBuilder->expr()->eq('session', $queryBuilder->createNamedParameter($sessionUid, ParameterType::INTEGER)),
                $queryBuilder->expr()->neq('attachments', $queryBuilder->createNamedParameter('')),
            )
            ->executeQuery()
            ->fetchFirstColumn()
        ;

        $uids = [];
        foreach ($rows as $row) {
            /** @var mixed $decoded */
            $decoded = json_decode((string) $row, true);
            if (!is_array($decoded)) {
                continue;
            }
            foreach ($decoded as $entry) {
                $uid = is_array($entry) ? ($entry['uid'] ?? null) : $entry;
                if (is_numeric($uid) && (int) $uid > 0) {
                    $uids[] = (int) $uid;
                }
            }
        }

        return array_values(array_unique($uids));
    }

    /**
     * @throws Exception
     * @throws \Doctrine\DBAL\Driver\Exception
     */
    public function findLastRole(int $sessionUid): string
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($this->table);
        $queryBuilder->getRestrictions()->removeAll();

        $role = $queryBuilder
            ->select('role')
            ->from($this->table)
            ->where($queryBuilder->expr()->eq('session', $queryBuilder->createNamedParameter($sessionUid, ParameterType::INTEGER)))
            ->orderBy('sort', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne()
        ;

        return is_string($role) ? $role : '';
    }

    /**
     * @param array<string, mixed> $data
     */
    public function append(int $sessionUid, array $data): int
    {
        $now = time();
        $nextSort = $this->nextSortForSession($sessionUid);
        $connection = $this->connectionPool->getConnectionForTable($this->table);

        $connection->insert($this->table, array_merge([
            'session' => $sessionUid,
            'sort' => $nextSort,
            'role' => '',
            'content' => '',
            'tool_calls' => '',
            'tool_call_id' => '',
            'tool_status' => '',
            'crdate' => $now,
            'tstamp' => $now,
        ], $data));

        return (int) $connection->lastInsertId();
    }

    public function updateToolStatus(int $messageUid, string $newStatus): void
    {
        $this->connectionPool->getConnectionForTable($this->table)
            ->update(
                $this->table,
                ['tool_status' => $newStatus, 'tstamp' => time()],
                ['uid' => $messageUid],
            )
        ;
    }

    /**
     * @param list<int> $messageUids
     */
    public function replaceWithSummary(int $sessionUid, array $messageUids, string $summaryContent): int
    {
        $connection = $this->connectionPool->getConnectionForTable($this->table);
        $connection->beginTransaction();

        try {
            if ([] !== $messageUids) {
                $queryBuilder = $connection->createQueryBuilder();
                $queryBuilder
                    ->delete($this->table)
                    ->where($queryBuilder->expr()->in(
                        'uid',
                        $queryBuilder->createNamedParameter($messageUids, Connection::PARAM_INT_ARRAY)
                    ))
                    ->executeStatement()
                ;
            }

            $summaryUid = $this->append($sessionUid, [
                'role' => ChatMessage::ROLE_SUMMARY,
                'content' => $summaryContent,
            ]);

            $connection->commit();

            return $summaryUid;
        } catch (\Throwable $e) {
            $connection->rollBack();

            throw $e;
        }
    }

    public function hardDeleteBySession(int $sessionUid): void
    {
        $connection = $this->connectionPool->getConnectionForTable($this->table);
        $queryBuilder = $connection->createQueryBuilder();
        $queryBuilder
            ->delete($this->table)
            ->where($queryBuilder->expr()->eq(
                'session',
                $queryBuilder->createNamedParameter($sessionUid, ParameterType::INTEGER)
            ))
            ->executeStatement()
        ;
    }

    /**
     * @throws Exception
     * @throws \Doctrine\DBAL\Driver\Exception
     */
    private function nextSortForSession(int $sessionUid): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($this->table);
        $queryBuilder->getRestrictions()->removeAll();

        $maxSort = $queryBuilder
            ->select('sort')
            ->from($this->table)
            ->where($queryBuilder->expr()->eq('session', $queryBuilder->createNamedParameter($sessionUid, ParameterType::INTEGER)))
            ->orderBy('sort', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne()
        ;

        return false === $maxSort ? 0 : ((int) $maxSort) + 1;
    }
}
