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

namespace AutoDudes\Cheddi\Service\Chat\OpenItem;

use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

class OpenItemRulePreview
{
    private const EXAMPLES = 3;

    public function __construct(
        private readonly CustomRulesSource $customRules,
        private readonly OpenItemRuleValidator $validator,
        private readonly OpenItemRuleAccess $access,
    ) {}

    /**
     * @param array<string, mixed> $rule
     *
     * @return null|array{count: int, tooMany: bool, examples: list<array{label: string, pageTitle: string, uid: int, pageId: int}>, problem: null|string}
     */
    public function preview(BackendUserAuthentication $backendUser, array $rule, int $now): ?array
    {
        if (!$this->access->mayEdit($backendUser, $rule)) {
            return null;
        }

        $problem = $this->validator->conditionProblemOf($rule);
        if (null !== $problem) {
            return ['count' => 0, 'tooMany' => false, 'examples' => [], 'problem' => $this->validator->problemLabel($problem)];
        }

        $table = (string) ($rule['source_table'] ?? '');
        $matches = $this->customRules->matchesOf($rule, $now, CustomRulesSource::MAX_MATCHES_PER_RULE + 1);
        $labelReadable = '' !== (string) ($rule['source'] ?? '') || $this->access->mayUseField($backendUser, $table, (string) ($GLOBALS['TCA'][$table]['ctrl']['label'] ?? ''));
        $permsClause = $backendUser->getPagePermsClause(Permission::PAGE_SHOW);

        $titles = [];
        $count = 0;
        $examples = [];
        foreach ($matches as $match) {
            $pageUid = $match['pageUid'];
            if (!array_key_exists($pageUid, $titles)) {
                $page = BackendUtility::readPageAccess($pageUid, $permsClause);
                $titles[$pageUid] = is_array($page) ? (string) ($page['title'] ?? '') : null;
            }
            if (null === $titles[$pageUid]) {
                continue;
            }

            ++$count;
            if (count($examples) < self::EXAMPLES) {
                $examples[] = ['label' => $labelReadable ? $match['label'] : '', 'pageTitle' => $titles[$pageUid], 'uid' => $match['recordUid'], 'pageId' => $pageUid];
            }
        }

        return [
            'count' => $count,
            'tooMany' => count($matches) > CustomRulesSource::MAX_MATCHES_PER_RULE,
            'examples' => $examples,
            'problem' => null,
        ];
    }
}
