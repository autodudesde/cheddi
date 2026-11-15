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
use AutoDudes\Cheddi\Domain\Model\Dto\OpenItemCandidate;
use AutoDudes\Cheddi\Enumeration\OpenItemAreaEnumeration;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Core\Database\ConnectionPool;

class OpenItemRepository extends AbstractRepository
{
    public const TABLE = 'tx_cheddi_open_item';
    public const RESOLUTION_TABLE = 'tx_cheddi_open_item_resolution';

    public function __construct(
        ConnectionPool $connectionPool,
        WorkspaceContextService $workspaceContextService,
        string $table = self::TABLE,
        string $sortBy = 'crdate',
    ) {
        parent::__construct($connectionPool, $workspaceContextService, $table, $sortBy);
    }

    public function remember(string $kind, OpenItemCandidate $candidate, int $now): void
    {
        $area = OpenItemAreaEnumeration::normalize($candidate->area);
        $connection = $this->connectionPool->getConnectionForTable($this->table);
        $values = [
            'tstamp' => $now,
            'severity' => $candidate->severity,
            'message' => $candidate->message,
            'reference_ts' => $candidate->referenceTs,
            'record_table' => $candidate->recordTable,
            'record_uid' => $candidate->recordUid,
            'area' => $area,
            'last_seen' => $now,
        ];

        $uid = $this->findUid($candidate->pageUid, $candidate->itemKey);
        if (null !== $uid) {
            $connection->update($this->table, $values, ['uid' => $uid]);

            return;
        }

        $connection->insert($this->table, $values + [
            'pid' => 0,
            'crdate' => $now,
            'kind' => $kind,
            'page_uid' => $candidate->pageUid,
            'item_key' => $candidate->itemKey,
        ]);
    }

    public function touchByKeyPrefix(string $prefix, int $now): void
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($this->table);
        $queryBuilder
            ->update($this->table)
            ->set('last_seen', $now)
            ->where($queryBuilder->expr()->like('item_key', $queryBuilder->createNamedParameter($queryBuilder->escapeLikeWildcards($prefix).'%')))
            ->executeStatement()
        ;
    }

    /**
     * @param list<string> $kinds
     */
    public function removeNotSeenSince(int $timestamp, array $kinds): int
    {
        if ([] === $kinds) {
            return 0;
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($this->table);
        $queryBuilder->getRestrictions()->removeAll();
        $rows = $queryBuilder
            ->select('uid')
            ->from($this->table)
            ->where(
                $queryBuilder->expr()->lt('last_seen', $queryBuilder->createNamedParameter($timestamp, ParameterType::INTEGER)),
                $queryBuilder->expr()->in('kind', $queryBuilder->createNamedParameter($kinds, ArrayParameterType::STRING)),
            )
            ->executeQuery()
            ->fetchAllAssociative()
        ;

        return $this->removeItems(array_map(static fn (array $row): int => (int) $row['uid'], $rows));
    }

    /**
     * @param list<string> $kinds
     */
    public function removeKindsOtherThan(array $kinds): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($this->table);
        $queryBuilder->getRestrictions()->removeAll();
        $queryBuilder->select('uid')->from($this->table);
        if ([] !== $kinds) {
            $queryBuilder->where($queryBuilder->expr()->notIn('kind', $queryBuilder->createNamedParameter($kinds, ArrayParameterType::STRING)));
        }

        return $this->removeItems(array_map('intval', $queryBuilder->executeQuery()->fetchFirstColumn()));
    }

    public function removeByKeyPrefix(string $prefix, ?int $notSeenSince = null): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($this->table);
        $queryBuilder->getRestrictions()->removeAll();
        $queryBuilder
            ->select('uid')
            ->from($this->table)
            ->where($queryBuilder->expr()->like('item_key', $queryBuilder->createNamedParameter($queryBuilder->escapeLikeWildcards($prefix).'%')))
        ;
        if (null !== $notSeenSince) {
            $queryBuilder->andWhere($queryBuilder->expr()->lt('last_seen', $queryBuilder->createNamedParameter($notSeenSince, ParameterType::INTEGER)));
        }

        return $this->removeItems(array_map('intval', $queryBuilder->executeQuery()->fetchFirstColumn()));
    }

    /**
     * @return array<int, int>
     */
    public function countPerPageByKeyPrefix(string $prefix): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($this->table);
        $queryBuilder->getRestrictions()->removeAll();
        $rows = $queryBuilder
            ->select('page_uid')
            ->addSelectLiteral('COUNT(*) AS items')
            ->from($this->table)
            ->where($queryBuilder->expr()->like('item_key', $queryBuilder->createNamedParameter($queryBuilder->escapeLikeWildcards($prefix).'%')))
            ->groupBy('page_uid')
            ->executeQuery()
            ->fetchAllAssociative()
        ;

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['page_uid']] = (int) $row['items'];
        }

        return $counts;
    }

    /**
     * @return list<array{uid: int, kind: string, page_uid: int, item_key: string, severity: string, message: string, reference_ts: int, record_table: string, record_uid: int, area: string, crdate: int}>
     */
    public function findAllOldestFirst(int $onlyUid = 0): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($this->table);
        $queryBuilder->getRestrictions()->removeAll();
        $queryBuilder
            ->select('uid', 'kind', 'page_uid', 'item_key', 'severity', 'message', 'reference_ts', 'record_table', 'record_uid', 'area', 'crdate')
            ->from($this->table)
            ->orderBy('reference_ts', 'ASC')
            ->addOrderBy('uid', 'ASC')
        ;
        if ($onlyUid > 0) {
            $queryBuilder->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($onlyUid, ParameterType::INTEGER)));
        }
        $rows = $queryBuilder->executeQuery()->fetchAllAssociative();

        return array_map(static fn (array $row): array => [
            'uid' => (int) $row['uid'],
            'kind' => (string) $row['kind'],
            'page_uid' => (int) $row['page_uid'],
            'item_key' => (string) $row['item_key'],
            'severity' => (string) $row['severity'],
            'message' => (string) $row['message'],
            'reference_ts' => (int) $row['reference_ts'],
            'record_table' => (string) $row['record_table'],
            'record_uid' => (int) $row['record_uid'],
            'area' => (string) $row['area'],
            'crdate' => (int) $row['crdate'],
        ], $rows);
    }

    /**
     * @return array<int, string>
     */
    public function findResolvedFingerprints(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::RESOLUTION_TABLE);
        $queryBuilder->getRestrictions()->removeAll();
        $rows = $queryBuilder
            ->select('item', 'fingerprint')
            ->from(self::RESOLUTION_TABLE)
            ->orderBy('uid', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative()
        ;

        $fingerprints = [];
        foreach ($rows as $row) {
            $fingerprints[(int) $row['item']] = (string) $row['fingerprint'];
        }

        return $fingerprints;
    }

    public function addResolution(int $itemUid, string $fingerprint, int $beUser, string $area, string $mode, int $now): void
    {
        $this->connectionPool->getConnectionForTable(self::RESOLUTION_TABLE)->insert(self::RESOLUTION_TABLE, [
            'pid' => 0,
            'crdate' => $now,
            'item' => $itemUid,
            'fingerprint' => $fingerprint,
            'be_user' => $beUser,
            'area' => $area,
            'mode' => $mode,
        ]);
    }

    /**
     * @return null|array{uid: int, kind: string, page_uid: int, item_key: string, severity: string, message: string, reference_ts: int, record_table: string, record_uid: int, area: string, crdate: int}
     */
    public function findItem(int $itemUid): ?array
    {
        foreach ($this->findAllOldestFirst($itemUid) as $row) {
            return $row;
        }

        return null;
    }

    public function findPageOf(int $itemUid): ?int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($this->table);
        $queryBuilder->getRestrictions()->removeAll();
        $pageUid = $queryBuilder
            ->select('page_uid')
            ->from($this->table)
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($itemUid, ParameterType::INTEGER)))
            ->executeQuery()
            ->fetchOne()
        ;

        return false === $pageUid ? null : (int) $pageUid;
    }

    private function findUid(int $pageUid, string $itemKey): ?int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($this->table);
        $queryBuilder->getRestrictions()->removeAll();
        $uid = $queryBuilder
            ->select('uid')
            ->from($this->table)
            ->where(
                $queryBuilder->expr()->eq('page_uid', $queryBuilder->createNamedParameter($pageUid, ParameterType::INTEGER)),
                $queryBuilder->expr()->eq('item_key', $queryBuilder->createNamedParameter($itemKey)),
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne()
        ;

        return false === $uid ? null : (int) $uid;
    }

    /**
     * @param list<int> $uids
     */
    private function removeItems(array $uids): int
    {
        $removed = 0;
        foreach (array_chunk($uids, 500) as $chunk) {
            $removed += $this->deleteWhereIn($this->table, 'uid', $chunk);
        }

        return $removed;
    }

    /**
     * @param list<int> $uids
     */
    private function deleteWhereIn(string $table, string $column, array $uids): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);

        return (int) $queryBuilder
            ->delete($table)
            ->where($queryBuilder->expr()->in($column, $queryBuilder->createNamedParameter($uids, ArrayParameterType::INTEGER)))
            ->executeStatement()
        ;
    }
}
