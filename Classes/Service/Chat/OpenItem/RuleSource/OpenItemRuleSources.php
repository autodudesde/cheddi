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

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

class OpenItemRuleSources
{
    /**
     * @var array<string, OpenItemRuleSource>
     */
    private array $sources = [];

    /**
     * @param iterable<OpenItemRuleSource> $sources
     */
    public function __construct(
        #[AutowireIterator(OpenItemRuleSource::TAG)]
        iterable $sources = [],
    ) {
        foreach ($sources as $source) {
            $this->sources[$source->identifier()] = $source;
        }
    }

    public function get(string $identifier): ?OpenItemRuleSource
    {
        $source = $this->sources[$identifier] ?? null;

        return null !== $source && $source->isAvailable() ? $source : null;
    }

    /**
     * @param array<string, mixed> $rule
     *
     * @return array<string, string>
     */
    public static function parametersOf(array $rule): array
    {
        $parameters = [];
        foreach ($rule['conditions'] ?? [] as $condition) {
            $name = (string) ($condition['field_name'] ?? '');
            if ('' !== $name) {
                $parameters[$name] = (string) ($condition['compare_value'] ?? '');
            }
        }

        return $parameters;
    }
}
