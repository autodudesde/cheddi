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

class ChatUploadRepository extends AbstractRepository
{
    public const TABLE = 'tx_cheddi_upload';

    public function __construct(
        ConnectionPool $connectionPool,
        WorkspaceContextService $workspaceContextService,
        string $table = self::TABLE,
        string $sortBy = '',
    ) {
        parent::__construct($connectionPool, $workspaceContextService, $table, $sortBy);
    }

    public function register(int $fileUid, int $beUserUid, int $now): void
    {
        $this->connectionPool->getConnectionForTable(self::TABLE)->insert(self::TABLE, [
            'file' => $fileUid,
            'be_user' => $beUserUid,
            'crdate' => $now,
        ]);
    }

    /**
     * @return list<int>
     */
    public function findFileUidsRegisteredBefore(int $cutoff): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();

        $uids = $queryBuilder
            ->select('file')
            ->from(self::TABLE)
            ->where($queryBuilder->expr()->lt('crdate', $queryBuilder->createNamedParameter($cutoff, ParameterType::INTEGER)))
            ->executeQuery()
            ->fetchFirstColumn()
        ;

        return array_values(array_unique(array_map('intval', $uids)));
    }

    public function isRegistered(int $fileUid): bool
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();

        return false !== $queryBuilder
            ->select('uid')
            ->from(self::TABLE)
            ->where($queryBuilder->expr()->eq('file', $queryBuilder->createNamedParameter($fileUid, ParameterType::INTEGER)))
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne()
        ;
    }

    public function forget(int $fileUid): void
    {
        $this->connectionPool->getConnectionForTable(self::TABLE)->delete(self::TABLE, ['file' => $fileUid]);
    }
}
