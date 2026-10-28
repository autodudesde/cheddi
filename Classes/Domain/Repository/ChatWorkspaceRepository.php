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
use TYPO3\CMS\Core\Database\Query\Restriction\HiddenRestriction;

class ChatWorkspaceRepository extends AbstractRepository
{
    public const TABLE = 'sys_workspace';

    private const OWNER_FIELD = 'tx_cheddi_owner';

    private const TITLE = 'ChEddi: %s';

    public function __construct(
        ConnectionPool $connectionPool,
        WorkspaceContextService $workspaceContextService,
        string $table = self::TABLE,
        string $sortBy = '',
    ) {
        parent::__construct($connectionPool, $workspaceContextService, $table, $sortBy);
    }

    public function findOwnedBy(int $beUserUid): ?int
    {
        if ($beUserUid <= 0) {
            return null;
        }

        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $qb->getRestrictions()->removeByType(HiddenRestriction::class);
        $uid = $qb
            ->select('uid')
            ->from(self::TABLE)
            ->where(
                $qb->expr()->eq(self::OWNER_FIELD, $qb->createNamedParameter($beUserUid, ParameterType::INTEGER)),
                $qb->expr()->inSet('members', $qb->createNamedParameter($this->memberToken($beUserUid))),
            )
            ->orderBy('uid', 'ASC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne()
        ;

        return false === $uid ? null : (int) $uid;
    }

    public function createFor(int $beUserUid, string $name): int
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);

        $connection->insert(self::TABLE, [
            'pid' => 0,
            'title' => sprintf(self::TITLE, $name),
            'description' => sprintf('Auto-created ChEddi draft workspace for backend user "%s" (#%d).', $name, $beUserUid),
            'members' => $this->memberToken($beUserUid),
            self::OWNER_FIELD => $beUserUid,
            'tstamp' => (int) ($GLOBALS['EXEC_TIME'] ?? time()),
        ]);

        return (int) $connection->lastInsertId();
    }

    private function memberToken(int $beUserUid): string
    {
        return 'be_users_'.$beUserUid;
    }
}
