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

use AutoDudes\AiSuite\Domain\Repository\PagesRepository;
use AutoDudes\Cheddi\Enumeration\OpenItemRuleComparisonEnumeration as Comparison;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;

class OpenItemRuleRepository
{
    public const TABLE = 'tx_cheddi_open_item_rule';
    public const CONDITION_TABLE = 'tx_cheddi_open_item_rule_condition';

    public const ORIGIN_SUGGESTED = 'suggested';

    private const INTEGER_TYPES = ['number', 'datetime', 'check', 'file', 'inline'];

    private const FIRST_NON_CONTENT_DOKTYPE = 199;

    private const FILE_TYPE_IMAGE = 2;

    private const METADATA_PLACEHOLDER = '/^__row\|([a-z_]+)\|metadata\|([a-z_]+)$/';

    private const TEXT_LABEL_TYPES = ['input', 'text', 'email', 'link', 'slug'];

    private const WRITABLE_COLUMNS = ['title', 'hidden', 'source', 'area', 'source_table', 'match_mode', 'preset', 'root_page', 'severity', 'inapplicable', 'customized'];

    private const CONDITION_COLUMNS = ['field_name', 'comparison', 'compare_value', 'related_table', 'related_field'];

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly PagesRepository $pagesRepository,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function findActive(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();

        return $this->withConditions($queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->where($queryBuilder->expr()->eq('deleted', 0), $queryBuilder->expr()->eq('hidden', 0), $queryBuilder->expr()->eq('inapplicable', 0))
            ->executeQuery()
            ->fetchAllAssociative());
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findAllNotDeleted(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();

        return $this->withConditions($queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->where($queryBuilder->expr()->eq('deleted', 0))
            ->orderBy('title', 'ASC')
            ->addOrderBy('uid', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative());
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findAllIncludingDeleted(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();

        return $queryBuilder
            ->select('uid', 'preset', 'deleted', 'hidden', 'inapplicable', 'customized')
            ->from(self::TABLE)
            ->where($queryBuilder->expr()->neq('preset', $queryBuilder->createNamedParameter('')))
            ->executeQuery()
            ->fetchAllAssociative()
        ;
    }

    public function updatePresetState(int $uid, ?string $area, bool $inapplicable): void
    {
        $this->connectionPool->getConnectionForTable(self::TABLE)->update(self::TABLE, ['inapplicable' => (int) $inapplicable] + (null === $area ? [] : ['area' => $area]), ['uid' => $uid]);
    }

    /**
     * @param array<string, mixed> $values
     */
    public function insertRule(array $values, int $now): int
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $connection->insert(self::TABLE, $this->writableValues($values) + ['pid' => 0, 'crdate' => $now, 'tstamp' => $now]);
        $uid = (int) $connection->lastInsertId();
        $this->replaceConditions($uid, $values['conditions'] ?? []);

        return $uid;
    }

    /**
     * @param array<string, mixed> $values
     */
    public function updateRule(int $uid, array $values, int $now): void
    {
        $this->connectionPool->getConnectionForTable(self::TABLE)->update(self::TABLE, $this->writableValues($values) + ['tstamp' => $now], ['uid' => $uid]);
        if (isset($values['conditions'])) {
            $this->replaceConditions($uid, $values['conditions']);
        }
    }

    public function setHidden(int $uid, bool $hidden, int $now): void
    {
        $this->connectionPool->getConnectionForTable(self::TABLE)->update(self::TABLE, ['hidden' => (int) $hidden, 'tstamp' => $now], ['uid' => $uid]);
    }

    public function markSuggested(int $uid): void
    {
        $this->connectionPool->getConnectionForTable(self::TABLE)->update(self::TABLE, ['origin' => self::ORIGIN_SUGGESTED], ['uid' => $uid]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findDeletedPresets(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();

        return $this->withConditions($queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->where($queryBuilder->expr()->eq('deleted', 1), $queryBuilder->expr()->neq('preset', $queryBuilder->createNamedParameter('')))
            ->orderBy('title', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative());
    }

    public function restoreHidden(int $uid, int $now): void
    {
        $this->connectionPool->getConnectionForTable(self::TABLE)->update(self::TABLE, ['deleted' => 0, 'hidden' => 1, 'tstamp' => $now], ['uid' => $uid]);
    }

    public function markDeleted(int $uid, int $now): void
    {
        $this->connectionPool->getConnectionForTable(self::TABLE)->update(self::TABLE, ['deleted' => 1, 'tstamp' => $now], ['uid' => $uid]);
    }

    /**
     * @return null|array<string, mixed>
     */
    public function findRule(int $uid): ?array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();
        $row = $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, ParameterType::INTEGER)))
            ->executeQuery()
            ->fetchAssociative()
        ;

        return false === $row ? null : $this->withConditions([$row])[0];
    }

    public function recordStatus(int $uid, string $status): void
    {
        $this->connectionPool->getConnectionForTable(self::TABLE)->update(self::TABLE, ['last_status' => $status], ['uid' => $uid]);
    }

    /**
     * @return list<array{uid: int, title: string, problem: string}>
     */
    public function findRuleProblems(): array
    {
        $problems = [];
        foreach ($this->findActive() as $rule) {
            if ('' !== (string) $rule['last_status']) {
                $problems[] = ['uid' => (int) $rule['uid'], 'title' => (string) $rule['title'], 'problem' => (string) $rule['last_status']];
            }
        }

        return $problems;
    }

    /**
     * @param array<string, mixed> $rule
     *
     * @return list<array{uid: int, pid: int, label: string, tstamp: int}>
     */
    public function findMatches(array $rule, int $now, int $limit): array
    {
        $table = (string) $rule['source_table'];
        $ctrl = $GLOBALS['TCA'][$table]['ctrl'];
        $labelField = (string) ($ctrl['label'] ?? 'uid');
        $tstampField = (string) ($ctrl['tstamp'] ?? '');

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        $queryBuilder->select('uid', 'pid', $labelField)->from($table);
        if ('' !== $tstampField) {
            $queryBuilder->addSelect($tstampField)->orderBy($tstampField, 'ASC');
        }
        $queryBuilder->addOrderBy('uid', 'ASC');

        $this->addRecordConstraints($queryBuilder, $table, $rule);
        $comparisons = array_map(
            fn (array $condition): string => $this->comparisonFor($queryBuilder, $table, $condition, $tstampField, $now),
            $rule['conditions'] ?? [],
        );
        if ([] === $comparisons) {
            throw new \InvalidArgumentException('A rule needs at least one condition.', 1793865601);
        }
        $queryBuilder->andWhere((string) ('any' === ($rule['match_mode'] ?? 'all') ? $queryBuilder->expr()->or(...$comparisons) : $queryBuilder->expr()->and(...$comparisons)));

        $rows = $queryBuilder->setMaxResults($limit)->executeQuery()->fetchAllAssociative();

        $labelIsText = in_array($GLOBALS['TCA'][$table]['columns'][$labelField]['config']['type'] ?? '', self::TEXT_LABEL_TYPES, true);

        return array_map(static fn (array $row): array => [
            'uid' => (int) $row['uid'],
            'pid' => (int) $row['pid'],
            'label' => $labelIsText ? (string) ($row[$labelField] ?? '') : '',
            'tstamp' => '' === $tstampField ? 0 : (int) $row[$tstampField],
        ], $rows);
    }

    /**
     * @param list<array<string, mixed>> $rules
     *
     * @return list<array<string, mixed>>
     */
    private function withConditions(array $rules): array
    {
        if ([] === $rules) {
            return [];
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::CONDITION_TABLE);
        $queryBuilder->getRestrictions()->removeAll();
        $rows = $queryBuilder
            ->select('rule', ...self::CONDITION_COLUMNS)
            ->from(self::CONDITION_TABLE)
            ->where($queryBuilder->expr()->in('rule', $queryBuilder->createNamedParameter(array_map(static fn (array $rule): int => (int) $rule['uid'], $rules), ArrayParameterType::INTEGER)))
            ->orderBy('rule', 'ASC')
            ->addOrderBy('sorting', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative()
        ;

        $byRule = [];
        foreach ($rows as $row) {
            $ruleUid = (int) $row['rule'];
            unset($row['rule']);
            $byRule[$ruleUid][] = $row;
        }

        return array_map(static fn (array $rule): array => $rule + ['conditions' => $byRule[(int) $rule['uid']] ?? []], $rules);
    }

    /**
     * @param list<array<string, mixed>> $conditions
     */
    private function replaceConditions(int $ruleUid, array $conditions): void
    {
        $connection = $this->connectionPool->getConnectionForTable(self::CONDITION_TABLE);
        $connection->delete(self::CONDITION_TABLE, ['rule' => $ruleUid]);
        foreach (array_values($conditions) as $index => $condition) {
            $connection->insert(self::CONDITION_TABLE, array_intersect_key($condition, array_flip(self::CONDITION_COLUMNS)) + ['rule' => $ruleUid, 'sorting' => $index]);
        }
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    private function writableValues(array $values): array
    {
        return array_intersect_key($values, array_flip(self::WRITABLE_COLUMNS));
    }

    /**
     * @param array<string, mixed> $rule
     */
    private function addRecordConstraints(QueryBuilder $queryBuilder, string $table, array $rule): void
    {
        $ctrl = $GLOBALS['TCA'][$table]['ctrl'];
        $expr = $queryBuilder->expr();
        if ('pages' === $table) {
            $queryBuilder->andWhere($expr->lt('doktype', self::FIRST_NON_CONTENT_DOKTYPE));
        }
        if (isset($ctrl['delete'])) {
            $queryBuilder->andWhere($expr->eq((string) $ctrl['delete'], 0));
        }
        if (isset($ctrl['enablecolumns']['disabled'])) {
            $queryBuilder->andWhere($expr->eq((string) $ctrl['enablecolumns']['disabled'], 0));
        }
        if (isset($ctrl['languageField'])) {
            $queryBuilder->andWhere($expr->in((string) $ctrl['languageField'], [0, -1]));
        }
        if (true === ($ctrl['versioningWS'] ?? false)) {
            $queryBuilder->andWhere($expr->eq('t3ver_wsid', 0));
        }

        $rootPage = (int) ($rule['root_page'] ?? 0);
        if ($rootPage > 0) {
            $scope = $this->pagesRepository->getSubtreePageIds($rootPage);
            $column = 'pages' === $table ? 'uid' : 'pid';
            $queryBuilder->andWhere($expr->in($column, $queryBuilder->createNamedParameter($scope, ArrayParameterType::INTEGER)));
        }
    }

    /**
     * @param array<string, mixed> $condition
     */
    private function comparisonFor(QueryBuilder $queryBuilder, string $table, array $condition, string $tstampField, int $now): string
    {
        $expr = $queryBuilder->expr();
        $field = (string) ($condition['field_name'] ?? '');
        $value = (string) ($condition['compare_value'] ?? '');
        $isInteger = $this->isIntegerColumn($table, $field);
        $parameter = fn (int|string $input): string => $queryBuilder->createNamedParameter($input, is_int($input) ? ParameterType::INTEGER : ParameterType::STRING);
        $typed = fn (string $input): string => $parameter($isInteger ? (int) $input : $input);
        $date = fn (int $timestamp): string => $parameter($isInteger ? $timestamp : date('Y-m-d H:i:s', $timestamp));

        switch ((string) ($condition['comparison'] ?? '')) {
            case Comparison::OLDER_THAN_DAYS:
                return (string) $expr->lt($tstampField, $parameter($now - max(1, (int) $value) * 86400));

            case Comparison::IS_EMPTY:
                return $this->emptyComparison($queryBuilder, $field, $isInteger);

            case Comparison::NOT_EMPTY:
                return $isInteger
                    ? (string) $expr->neq($field, 0)
                    : (string) $expr->and($expr->isNotNull($field), $expr->neq($field, $parameter('')));

            case Comparison::CONTAINS:
                return (string) $expr->like($field, $parameter('%'.$queryBuilder->escapeLikeWildcards($value).'%'));

            case Comparison::NOT_CONTAINS:
                return (string) $expr->or($expr->isNull($field), $expr->notLike($field, $parameter('%'.$queryBuilder->escapeLikeWildcards($value).'%')));

            case Comparison::EQUALS:
                return (string) $expr->eq($field, $typed($value));

            case Comparison::NOT_EQUALS:
                return (string) $expr->or($expr->isNull($field), $expr->neq($field, $typed($value)));

            case Comparison::GREATER:
                return (string) $expr->gt($field, $typed($value));

            case Comparison::LESS:
                return (string) $expr->lt($field, $typed($value));

            case Comparison::DATE_PASSED:
                return (string) $expr->and(
                    $isInteger ? $expr->gt($field, 0) : $expr->isNotNull($field),
                    $expr->lt($field, $date($now)),
                );

            case Comparison::DATE_WITHIN_DAYS:
                return (string) $expr->and(
                    $expr->gte($field, $date($now)),
                    $expr->lte($field, $date($now + max(1, (int) $value) * 86400)),
                );

            case Comparison::HAS_NO_RELATED:
                return $this->noRelatedComparison($table, (string) ($condition['related_table'] ?? ''), (string) ($condition['related_field'] ?? ''));

            case Comparison::EFFECTIVE_EMPTY:
                return $this->effectiveEmptyComparison($queryBuilder, $table, $field, $isInteger);
        }

        throw new \InvalidArgumentException('Unknown comparison.', 1793865600);
    }

    private function emptyComparison(QueryBuilder $queryBuilder, string $field, bool $isInteger): string
    {
        $expr = $queryBuilder->expr();

        return $isInteger
            ? (string) $expr->eq($field, 0)
            : (string) $expr->or($expr->isNull($field), $expr->eq($field, $queryBuilder->createNamedParameter('')));
    }

    private function noRelatedComparison(string $table, string $relatedTable, string $relatedField): string
    {
        $alias = 'related_row';
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($relatedTable);
        $queryBuilder->getRestrictions()->removeAll();
        $expr = $queryBuilder->expr();
        $ctrl = $GLOBALS['TCA'][$relatedTable]['ctrl'] ?? [];
        $queryBuilder
            ->select($alias.'.uid')
            ->from($relatedTable, $alias)
            ->where($expr->eq($alias.'.'.$relatedField, $queryBuilder->quoteIdentifier($table.'.uid')))
        ;
        if (isset($ctrl['delete'])) {
            $queryBuilder->andWhere($expr->eq($alias.'.'.$ctrl['delete'], 0));
        }
        if (isset($ctrl['languageField'])) {
            $queryBuilder->andWhere($expr->in($alias.'.'.$ctrl['languageField'], [0, -1]));
        }
        if (true === ($ctrl['versioningWS'] ?? false)) {
            $queryBuilder->andWhere($expr->eq($alias.'.t3ver_wsid', 0));
        }

        return 'NOT EXISTS ('.$queryBuilder->getSQL().')';
    }

    private function effectiveEmptyComparison(QueryBuilder $queryBuilder, string $table, string $field, bool $isInteger): string
    {
        $placeholder = (string) ($GLOBALS['TCA'][$table]['columns'][$field]['config']['placeholder'] ?? '');
        if (1 !== preg_match(self::METADATA_PLACEHOLDER, $placeholder, $parts)) {
            return $this->emptyComparison($queryBuilder, $field, $isInteger);
        }

        [, $fileColumn, $metadataField] = $parts;
        $expr = $queryBuilder->expr();
        $tableFile = $table.'.'.$fileColumn;

        $metadata = $this->connectionPool->getQueryBuilderForTable('sys_file_metadata');
        $metadata->getRestrictions()->removeAll();
        $metadata
            ->select('metadata_row.uid')
            ->from('sys_file_metadata', 'metadata_row')
            ->where(
                $metadata->expr()->eq('metadata_row.file', $metadata->quoteIdentifier($tableFile)),
                $metadata->expr()->neq('metadata_row.'.$metadataField, $metadata->quote('')),
            )
        ;

        $file = $this->connectionPool->getQueryBuilderForTable('sys_file');
        $file->getRestrictions()->removeAll();
        $file
            ->select('file_row.uid')
            ->from('sys_file', 'file_row')
            ->where(
                $file->expr()->eq('file_row.uid', $file->quoteIdentifier($tableFile)),
                $file->expr()->eq('file_row.missing', 0),
                $file->expr()->eq('file_row.type', self::FILE_TYPE_IMAGE),
            )
        ;

        return (string) $expr->and(
            'EXISTS ('.$file->getSQL().')',
            $expr->or(
                $expr->eq($field, $queryBuilder->createNamedParameter('')),
                $expr->and($expr->isNull($field), 'NOT EXISTS ('.$metadata->getSQL().')'),
            ),
        );
    }

    private function isIntegerColumn(string $table, string $field): bool
    {
        $config = $GLOBALS['TCA'][$table]['columns'][$field]['config'] ?? [];

        return in_array($config['type'] ?? '', self::INTEGER_TYPES, true) && !isset($config['dbType']);
    }
}
