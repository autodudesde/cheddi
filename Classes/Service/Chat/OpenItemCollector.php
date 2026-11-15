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

namespace AutoDudes\Cheddi\Service\Chat;

use AutoDudes\Cheddi\Domain\Repository\OpenItemRepository;
use AutoDudes\Cheddi\Domain\Repository\OpenItemRuleRepository;
use AutoDudes\Cheddi\Service\Chat\OpenItem\CustomRulesSource;
use AutoDudes\Cheddi\Service\Chat\OpenItem\OpenItemCatalog;
use AutoDudes\Cheddi\Service\Chat\OpenItem\OpenItemSource;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

class OpenItemCollector
{
    /**
     * @param iterable<OpenItemSource> $sources
     */
    public function __construct(
        private readonly OpenItemRepository $openItems,
        private readonly OpenItemRuleRepository $ruleRepository,
        private readonly CustomRulesSource $customRules,
        private readonly OpenItemCatalog $catalog,
        private readonly LoggerInterface $logger,
        #[AutowireIterator(OpenItemSource::TAG)]
        private readonly iterable $sources = [],
    ) {}

    /**
     * @return array<string, int>
     */
    public function run(?int $now = null): array
    {
        $now ??= time();
        $this->catalog->sync($now);
        $counts = [];
        $completed = [];
        foreach ($this->sources as $source) {
            $counts[$source->kind()] = 0;

            try {
                foreach ($source->collect($now) as $candidate) {
                    $this->openItems->remember($source->kind(), $candidate, $now);
                    ++$counts[$source->kind()];
                }
                $completed[] = $source->kind();
            } catch (\Throwable $e) {
                $this->logger->warning('ChEddi: an open item source failed, its earlier items stay', [
                    'kind' => $source->kind(),
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        $removed = $this->openItems->removeNotSeenSince($now, $completed) + $this->openItems->removeKindsOtherThan(array_keys($counts));

        return $counts + ['removed' => $removed];
    }

    public function refreshRule(int $ruleUid, ?int $now = null): void
    {
        $now ??= time();
        $prefix = CustomRulesSource::keyPrefix($ruleUid);
        $rule = $this->ruleRepository->findRule($ruleUid);
        if (null === $rule || 0 !== (int) $rule['deleted'] || 0 !== (int) $rule['hidden'] || 0 !== (int) ($rule['inapplicable'] ?? 0)) {
            $this->openItems->removeByKeyPrefix($prefix);

            return;
        }

        foreach ($this->customRules->collectRule($rule, $now) as $candidate) {
            $this->openItems->remember(CustomRulesSource::KIND, $candidate, $now);
        }
        $this->openItems->removeByKeyPrefix($prefix, $now);
    }
}
