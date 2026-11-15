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

namespace AutoDudes\Cheddi\Service\Chat\OpenItem\RuleSource;

use AutoDudes\AiSuite\Domain\Repository\AuditResultRepository;
use AutoDudes\AiSuite\Service\LocalizationService;
use AutoDudes\Cheddi\Domain\Repository\OpenItemSourceRepository;
use AutoDudes\Cheddi\Enumeration\OpenItemRuleProblemEnumeration;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

final class AuditRuleSource implements OpenItemRuleSource
{
    private const LABEL_PREFIX = '%sauditType.';
    private const MAX_AGE_SECONDS = 14 * 86400;
    private const FINDING_TYPES = ['seo', 'a11y'];
    private const TOPIC_TYPES = ['questions' => ['questions', 'question'], 'gap' => ['gaps', 'keyword'], 'competitors' => ['gaps', 'keyword'], 'cluster' => ['clusters', 'label']];
    private const SEVERITY_RANK = ['notice' => 1, 'warning' => 2, 'error' => 3];
    private const PERMISSION = 'tx_aisuite_features:enable_audit';

    public function __construct(
        private readonly OpenItemSourceRepository $sources,
        private readonly AuditResultRepository $auditResults,
        private readonly LocalizationService $localizationService,
    ) {}

    public function identifier(): string
    {
        return 'audit';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function problemOf(array $parameters): ?string
    {
        $type = $parameters['audit_type'] ?? '';
        if (!in_array($type, self::FINDING_TYPES, true) && !isset(self::TOPIC_TYPES[$type])) {
            return OpenItemRuleProblemEnumeration::SOURCE_UNAVAILABLE;
        }

        return isset($parameters['severity']) && !isset(self::SEVERITY_RANK[$parameters['severity']]) ? OpenItemRuleProblemEnumeration::SOURCE_UNAVAILABLE : null;
    }

    public function mayUse(BackendUserAuthentication $backendUser): bool
    {
        return $backendUser->isAdmin() || $backendUser->check('custom_options', self::PERMISSION);
    }

    public function summary(array $parameters): string
    {
        $type = $this->localizationService->translate(sprintf(self::LABEL_PREFIX, 'LLL:EXT:cheddi/Resources/Private/Language/locallang.xlf:cheddi.openItemRule.source.').($parameters['audit_type'] ?? ''));
        $severity = $parameters['severity'] ?? '';

        return '' === $severity
            ? $type
            : $this->localizationService->translate('LLL:EXT:cheddi/Resources/Private/Language/locallang.xlf:cheddi.openItemRule.source.auditSeverity', [$type, $this->localizationService->translate('LLL:EXT:cheddi/Resources/Private/Language/locallang.xlf:cheddi.openItemRule.source.severity.'.$severity)]);
    }

    public function describeMatch(array $row): string
    {
        return $row['message'];
    }

    public function subgroupOf(array $row): ?array
    {
        return null;
    }

    public function matches(array $parameters, int $now, int $limit): array
    {
        $type = $parameters['audit_type'] ?? '';
        $minimum = self::SEVERITY_RANK[$parameters['severity'] ?? 'notice'] ?? 1;
        $matches = [];
        foreach ($this->sources->findAuditedPagesSince([$type], $now - self::MAX_AGE_SECONDS) as $audited) {
            $stored = $this->auditResults->findLatest($audited['pageUid'], $type);
            if (null === $stored) {
                continue;
            }
            $entries = in_array($type, self::FINDING_TYPES, true) ? $this->findingsOf($stored['result'], $minimum) : $this->topicsOf($stored['result'], $type);
            foreach ($entries as $key => $label) {
                $matches[] = ['pageUid' => $audited['pageUid'], 'key' => $key, 'label' => $label, 'tstamp' => (int) $stored['runTs'], 'recordTable' => '', 'recordUid' => 0];
                if (count($matches) >= $limit) {
                    return $matches;
                }
            }
        }

        return $matches;
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return array<string, string>
     */
    private function findingsOf(array $result, int $minimum): array
    {
        $audit = is_array($result['audit'] ?? null) ? $result['audit'] : [];
        $fixed = array_map('strval', is_array($result['fixedIssues'] ?? null) ? $result['fixedIssues'] : []);
        $topIssues = is_array($audit['a11y']['topIssues'] ?? null) ? $audit['a11y']['topIssues'] : [];
        $issues = is_array($audit['issues'] ?? null) ? $audit['issues'] : [];

        $findings = [];
        foreach ([...$topIssues, ...$issues] as $issue) {
            if (!is_array($issue)) {
                continue;
            }
            $id = (string) ($issue['id'] ?? $issue['code'] ?? '');
            $severity = (string) ($issue['severity'] ?? $issue['type'] ?? 'notice');
            if ('' === $id || in_array($id, $fixed, true) || (self::SEVERITY_RANK[$severity] ?? 1) < $minimum) {
                continue;
            }
            $findings[$id] = (string) ($issue['message'] ?? $id);
        }

        return $findings;
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return array<string, string>
     */
    private function topicsOf(array $result, string $type): array
    {
        [$listKey, $labelKey] = self::TOPIC_TYPES[$type];
        $topics = [];
        foreach (is_array($result[$listKey] ?? null) ? $result[$listKey] : [] as $topic) {
            if (!is_array($topic) || 'answered' === ($topic['status'] ?? '')) {
                continue;
            }
            $label = trim((string) ($topic[$labelKey] ?? ''));
            if ('' !== $label) {
                $topics[hash('sha1', $label)] = $label;
            }
        }

        return $topics;
    }
}
