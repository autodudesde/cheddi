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

    public function resolve(ToolInterface $tool): Severity
    {
        return $this->policyCache[$tool->getName()] ??= $this->computePolicy($tool);
    }

    private function computePolicy(ToolInterface $tool): Severity
    {
        $annotations = $tool->getAnnotations();

        if (true === ($annotations['destructiveHint'] ?? false)) {
            return Severity::Destructive;
        }

        if (true === ($annotations['readOnlyHint'] ?? false)) {
            return Severity::ReadOnly;
        }

        return Severity::Write;
    }
}
