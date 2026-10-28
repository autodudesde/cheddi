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
use TYPO3\CMS\Core\Database\ConnectionPool;

class BackendGroupRepository extends AbstractRepository
{
    public const TABLE = 'be_groups';

    public function __construct(
        ConnectionPool $connectionPool,
        WorkspaceContextService $workspaceContextService,
        string $table = self::TABLE,
        string $sortBy = 'title',
    ) {
        parent::__construct($connectionPool, $workspaceContextService, $table, $sortBy);
    }

    /**
     * @return list<array{uid: int, title: string, folder: string}>
     */
    public function findWithChatUploadFolder(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);

        $rows = $queryBuilder
            ->select('uid', 'title', 'chatUploadFolder')
            ->from(self::TABLE)
            ->where($queryBuilder->expr()->neq('chatUploadFolder', $queryBuilder->createNamedParameter('')))
            ->orderBy('title')
            ->executeQuery()
            ->fetchAllAssociative()
        ;

        return array_map(static fn (array $row): array => [
            'uid' => (int) $row['uid'],
            'title' => (string) $row['title'],
            'folder' => trim((string) $row['chatUploadFolder']),
        ], $rows);
    }
}
