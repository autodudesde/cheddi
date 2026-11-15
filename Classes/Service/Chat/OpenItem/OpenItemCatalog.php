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

use AutoDudes\Cheddi\Domain\Repository\OpenItemRepository;
use AutoDudes\Cheddi\Domain\Repository\OpenItemRuleRepository;

class OpenItemCatalog
{
    public function __construct(
        private readonly OpenItemPresetCatalog $presets,
        private readonly OpenItemRuleRepository $rules,
        private readonly OpenItemRuleValidator $validator,
        private readonly OpenItemRepository $openItems,
    ) {}

    public function sync(int $now): void
    {
        $known = [];
        $active = [];
        foreach ($this->rules->findAllIncludingDeleted() as $rule) {
            $known[(string) $rule['preset']] = $rule;
            if (0 === (int) $rule['deleted']) {
                $active[(string) $rule['preset']] = (int) $rule['uid'];
            }
        }
        unset($known[''], $active['']);

        $presets = $this->presets->all();
        $offered = array_column($presets, 'identifier');
        foreach (array_diff_key($active, array_flip($offered)) as $uid) {
            $this->rules->markDeleted($uid, $now);
            $this->openItems->removeByKeyPrefix(CustomRulesSource::keyPrefix($uid));
        }

        foreach ($presets as $preset) {
            $values = $preset['values'];
            $existing = $known[$preset['identifier']] ?? null;
            $customized = null !== $existing && 0 !== (int) $existing['customized'];
            $inapplicable = null !== $this->validator->problemOf($customized ? ($this->rules->findRule((int) $existing['uid']) ?? $values) : $values);
            if (null === $existing) {
                $uid = $this->rules->insertRule($values + ['root_page' => 0, 'inapplicable' => (int) $inapplicable], $now);
                $this->rules->setHidden($uid, !$preset['recommended'], $now);

                continue;
            }
            $uid = (int) $existing['uid'];
            $this->rules->updatePresetState($uid, $customized ? null : (string) ($values['area'] ?? ''), $inapplicable);
            if ($inapplicable && 0 === (int) $existing['inapplicable']) {
                $this->openItems->removeByKeyPrefix(CustomRulesSource::keyPrefix($uid));
            }
        }
    }
}
