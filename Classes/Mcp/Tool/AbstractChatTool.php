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

namespace AutoDudes\Cheddi\Mcp\Tool;

use AutoDudes\AiSuiteMcp\Mcp\Tool\AbstractTool;

abstract class AbstractChatTool extends AbstractTool
{
    public function getRequiredScope(): ?string
    {
        return null;
    }

    // Not reachable over the transport, so there is no OAuth scope to check
    protected function validatePermissions(): void {}
}
