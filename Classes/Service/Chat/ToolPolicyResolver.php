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

use AutoDudes\AiSuiteMcp\Mcp\Tool\ToolInterface;
use AutoDudes\Cheddi\Domain\Enum\Severity;

class ToolPolicyResolver
{
    /**
     * @var array<string, Severity>
     */
    private array $policyCache = [];

    public function __construct(
        private readonly NamedUrlMatcher $namedUrlMatcher = new NamedUrlMatcher(),
    ) {}

    public function resolve(ToolInterface $tool): Severity
    {
        return $this->policyCache[$tool->getName()] ??= $this->computePolicy($tool);
    }

    /**
     * @param array<string, mixed> $arguments
     * @param list<string>         $editorTexts
     */
    public function resolveCall(ToolInterface $tool, array $arguments, array $editorTexts): Severity
    {
        $severity = $this->resolve($tool);
        if (Severity::ReadOnly !== $severity || !$this->fetchesAUrl($tool)) {
            return $severity;
        }

        $url = $arguments['url'] ?? null;

        return is_string($url) && $this->namedUrlMatcher->isNamed($url, $editorTexts)
            ? Severity::ReadOnly
            : Severity::Write;
    }

    private function fetchesAUrl(ToolInterface $tool): bool
    {
        return true === ($tool->getAnnotations()['openWorldHint'] ?? false)
            && isset($tool->getSchema()['properties']['url']);
    }

    private function computePolicy(ToolInterface $tool): Severity
    {
        $annotations = $tool->getAnnotations();

        if (true === ($annotations['destructiveHint'] ?? false)) {
            return Severity::Destructive;
        }

        if (true === ($annotations['readOnlyHint'] ?? false)) {
            // A billed read is confirmed like a write
            return ($tool->getCreditCost() ?? 0) > 0 ? Severity::Write : Severity::ReadOnly;
        }

        return Severity::Write;
    }
}
