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

class FileReferenceRepository extends AbstractRepository
{
    public const TABLE = 'sys_file_reference';

    public function __construct(
        ConnectionPool $connectionPool,
        WorkspaceContextService $workspaceContextService,
        string $table = self::TABLE,
        string $sortBy = '',
    ) {
        parent::__construct($connectionPool, $workspaceContextService, $table, $sortBy);
    }

    public function isFileReferenced(int $fileUid): bool
    {
        $references = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $references->getRestrictions()->removeAll();
        $referenceCount = (int) $references
            ->count('uid')
            ->from(self::TABLE)
            ->where($references->expr()->eq('uid_local', $references->createNamedParameter($fileUid, ParameterType::INTEGER)))
            ->executeQuery()
            ->fetchOne()
        ;
        if ($referenceCount > 0) {
            return true;
        }

        $refIndex = $this->connectionPool->getQueryBuilderForTable('sys_refindex');
        $refIndex->getRestrictions()->removeAll();

        return (int) $refIndex
            ->count('hash')
            ->from('sys_refindex')
            ->where(
                $refIndex->expr()->eq('ref_table', $refIndex->createNamedParameter('sys_file')),
                $refIndex->expr()->eq('ref_uid', $refIndex->createNamedParameter($fileUid, ParameterType::INTEGER)),
            )
            ->executeQuery()
            ->fetchOne() > 0
        ;
    }

    public function findFileUidOfReference(int $referenceUid): ?int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();

        $uidLocal = $queryBuilder
            ->select('uid_local')
            ->from(self::TABLE)
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($referenceUid, ParameterType::INTEGER)))
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne()
        ;

        return is_numeric($uidLocal) && (int) $uidLocal > 0 ? (int) $uidLocal : null;
    }
}
