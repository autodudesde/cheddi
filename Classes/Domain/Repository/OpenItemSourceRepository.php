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

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\BigIntType;
use Doctrine\DBAL\Types\BooleanType;
use Doctrine\DBAL\Types\DateImmutableType;
use Doctrine\DBAL\Types\DateTimeImmutableType;
use Doctrine\DBAL\Types\DateTimeType;
use Doctrine\DBAL\Types\DateType;
use Doctrine\DBAL\Types\IntegerType;
use Doctrine\DBAL\Types\SmallIntType;
use Doctrine\DBAL\Types\Type;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class OpenItemSourceRepository
{
    private const AUDIT_TABLE = 'tx_aisuite_audit_result';
    private const LINK_TABLE = 'tx_linkvalidator_link';
    private const QUESTION_TABLE = 'tx_freddi_question';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    /**
     * @return list<array{uid: int, tstamp: int}>
     */
    public function findLivePagesAfter(int $afterUid, int $limit): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll();
        $rows = $queryBuilder
            ->select('uid', 'tstamp')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->gt('uid', $queryBuilder->createNamedParameter($afterUid, ParameterType::INTEGER)),
                $queryBuilder->expr()->eq('deleted', 0),
                $queryBuilder->expr()->eq('hidden', 0),
                $queryBuilder->expr()->eq('sys_language_uid', 0),
                $queryBuilder->expr()->eq('t3ver_wsid', 0),
                $queryBuilder->expr()->eq('doktype', PageRepository::DOKTYPE_DEFAULT),
            )
            ->orderBy('uid', 'ASC')
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchAllAssociative()
        ;

        return array_map(static fn (array $row): array => ['uid' => (int) $row['uid'], 'tstamp' => (int) $row['tstamp']], $rows);
    }

    /**
     * @param list<int> $pageUids
     *
     * @return array<int, list<int>>
     */
    public function findTranslatedLanguages(array $pageUids): array
    {
        if ([] === $pageUids) {
            return [];
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll();
        $rows = $queryBuilder
            ->select('l10n_parent', 'sys_language_uid')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->in('l10n_parent', $queryBuilder->createNamedParameter($pageUids, ArrayParameterType::INTEGER)),
                $queryBuilder->expr()->eq('deleted', 0),
                $queryBuilder->expr()->eq('t3ver_wsid', 0),
            )
            ->executeQuery()
            ->fetchAllAssociative()
        ;

        $translated = [];
        foreach ($rows as $row) {
            $translated[(int) $row['l10n_parent']][] = (int) $row['sys_language_uid'];
        }

        return $translated;
    }

    /**
     * @param list<int> $pageUids
     *
     * @return array<int, int>
     */
    public function findLatestContentChanges(array $pageUids): array
    {
        if ([] === $pageUids) {
            return [];
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()->removeAll();
        $rows = $queryBuilder
            ->select('pid')
            ->addSelectLiteral($queryBuilder->expr()->max('tstamp', 'latest'))
            ->from('tt_content')
            ->where(
                $queryBuilder->expr()->in('pid', $queryBuilder->createNamedParameter($pageUids, ArrayParameterType::INTEGER)),
                $queryBuilder->expr()->eq('deleted', 0),
                $queryBuilder->expr()->eq('t3ver_wsid', 0),
            )
            ->groupBy('pid')
            ->executeQuery()
            ->fetchAllAssociative()
        ;

        $latest = [];
        foreach ($rows as $row) {
            $latest[(int) $row['pid']] = (int) $row['latest'];
        }

        return $latest;
    }

    /**
     * @return list<array{uid: int, record_uid: int, record_pid: int, table_name: string, field: string, url: string, last_check: int}>
     */
    public function findBrokenLinksAfter(int $afterUid, int $limit): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::LINK_TABLE);
        $queryBuilder->getRestrictions()->removeAll();
        $rows = $queryBuilder
            ->select('uid', 'record_uid', 'record_pid', 'table_name', 'field', 'url', 'last_check')
            ->from(self::LINK_TABLE)
            ->where($queryBuilder->expr()->gt('uid', $queryBuilder->createNamedParameter($afterUid, ParameterType::INTEGER)))
            ->orderBy('uid', 'ASC')
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchAllAssociative()
        ;

        return array_map(static fn (array $row): array => [
            'uid' => (int) $row['uid'],
            'record_uid' => (int) $row['record_uid'],
            'record_pid' => (int) $row['record_pid'],
            'table_name' => (string) $row['table_name'],
            'field' => (string) $row['field'],
            'url' => (string) $row['url'],
            'last_check' => (int) $row['last_check'],
        ], $rows);
    }

    /**
     * @param list<string> $auditTypes
     *
     * @return list<array{pageUid: int, auditType: string}>
     */
    public function findAuditedPagesSince(array $auditTypes, int $since): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::AUDIT_TABLE);
        $queryBuilder->getRestrictions()->removeAll();
        $rows = $queryBuilder
            ->select('page_uid', 'audit_type')
            ->from(self::AUDIT_TABLE)
            ->where(
                $queryBuilder->expr()->in('audit_type', $queryBuilder->createNamedParameter($auditTypes, ArrayParameterType::STRING)),
                $queryBuilder->expr()->eq('language_uid', 0),
                $queryBuilder->expr()->gte('run_ts', $queryBuilder->createNamedParameter($since, ParameterType::INTEGER)),
            )
            ->executeQuery()
            ->fetchAllAssociative()
        ;

        return array_map(static fn (array $row): array => ['pageUid' => (int) $row['page_uid'], 'auditType' => (string) $row['audit_type']], $rows);
    }

    /**
     * @return list<array{site: string, question: string, hash: string, crdate: int}>
     */
    public function findUnansweredQuestions(int $since, int $limit): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::QUESTION_TABLE);
        $queryBuilder->getRestrictions()->removeAll();
        $expr = $queryBuilder->expr();
        $rows = $queryBuilder
            ->select('site', 'question_hash')
            ->addSelectLiteral('MAX('.$queryBuilder->quoteIdentifier('question').') AS question', 'MAX('.$queryBuilder->quoteIdentifier('crdate').') AS crdate')
            ->from(self::QUESTION_TABLE)
            ->where(
                $expr->gte('crdate', $queryBuilder->createNamedParameter($since, ParameterType::INTEGER)),
                $expr->eq('answered', 0),
                $expr->or($expr->eq('signal_no_hits', 1), $expr->eq('signal_reported', 1)),
            )
            ->groupBy('site', 'question_hash')
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchAllAssociative()
        ;

        return array_map(static fn (array $row): array => [
            'site' => (string) $row['site'],
            'question' => (string) $row['question'],
            'hash' => (string) $row['question_hash'],
            'crdate' => (int) $row['crdate'],
        ], $rows);
    }

    public function pageOfRecord(string $table, int $uid): ?int
    {
        if ('pages' === $table) {
            return $uid > 0 ? $uid : null;
        }
        if ('' === $table || !isset($GLOBALS['TCA'][$table]) || 1 === (int) ($GLOBALS['TCA'][$table]['ctrl']['rootLevel'] ?? 0)) {
            return null;
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        $pid = $queryBuilder
            ->select('pid')
            ->from($table)
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, ParameterType::INTEGER)))
            ->executeQuery()
            ->fetchOne()
        ;

        return false === $pid ? null : (int) $pid;
    }

    /**
     * @param list<string> $fields
     *
     * @return list<array{page: int, total: int, empty: array<string, int>}>
     */
    public function fillRates(string $table, array $fields): array
    {
        $ctrl = $GLOBALS['TCA'][$table]['ctrl'] ?? [];
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        $pageColumn = 'pages' === $table ? 'uid' : 'pid';
        $queryBuilder->selectLiteral($queryBuilder->quoteIdentifier($pageColumn).' AS page', 'COUNT(*) AS total');
        $columns = $this->connectionPool->getConnectionForTable($table)->createSchemaManager()->introspectTable($table);
        $fields = array_values(array_filter($fields, static fn (string $field): bool => $columns->hasColumn($field)));
        foreach ($fields as $index => $field) {
            $queryBuilder->addSelectLiteral(sprintf('SUM(CASE WHEN %s THEN 1 ELSE 0 END) AS empty_%d', $this->emptyCondition($queryBuilder->quoteIdentifier($field), $columns->getColumn($field)->getType(), $this->zeroIsAValue($table, $field)), $index));
        }
        $queryBuilder->from($table)->groupBy($pageColumn);

        $expr = $queryBuilder->expr();
        if (isset($ctrl['languageField'])) {
            $queryBuilder->andWhere($expr->in((string) $ctrl['languageField'], [0, -1]));
        }
        if (true === ($ctrl['versioningWS'] ?? false)) {
            $queryBuilder->andWhere($expr->eq('t3ver_wsid', 0));
        }

        $rates = [];
        foreach ($queryBuilder->executeQuery()->fetchAllAssociative() as $row) {
            $empty = [];
            foreach ($fields as $index => $field) {
                $empty[$field] = (int) ($row['empty_'.$index] ?? 0);
            }
            $rates[] = ['page' => (int) $row['page'], 'total' => (int) $row['total'], 'empty' => $empty];
        }

        return $rates;
    }

    private function emptyCondition(string $column, Type $type, bool $zeroIsAValue): string
    {
        if ($type instanceof IntegerType || $type instanceof SmallIntType || $type instanceof BigIntType || $type instanceof BooleanType) {
            return $zeroIsAValue ? sprintf('%s IS NULL', $column) : sprintf('(%1$s IS NULL OR %1$s = 0)', $column);
        }
        if ($type instanceof DateImmutableType || $type instanceof DateTimeImmutableType || $type instanceof DateType || $type instanceof DateTimeType) {
            return sprintf('%s IS NULL', $column);
        }

        return $zeroIsAValue ? sprintf('(%1$s IS NULL OR %1$s = \'\')', $column) : sprintf('(%1$s IS NULL OR %1$s = \'\' OR %1$s = \'0\')', $column);
    }

    private function zeroIsAValue(string $table, string $field): bool
    {
        $config = $GLOBALS['TCA'][$table]['columns'][$field]['config'] ?? [];
        if ('number' === ($config['type'] ?? '')) {
            return true;
        }
        foreach ($config['items'] ?? [] as $item) {
            $value = is_array($item) ? ($item['value'] ?? $item[1] ?? null) : null;
            if (0 === $value || '0' === $value) {
                return true;
            }
        }

        return false;
    }
}
