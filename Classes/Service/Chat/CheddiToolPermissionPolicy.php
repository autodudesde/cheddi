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

use AutoDudes\AiSuiteMcp\Mcp\Service\ToolPermissionPolicy;
use AutoDudes\Cheddi\Enumeration\CheddiPermissionEnumeration;

final class CheddiToolPermissionPolicy implements ToolPermissionPolicy
{
    /**
     * @var array<string, list<string>>
     */
    private const SCOPE_PERMISSIONS = [
        'mcp:read' => [],
        'mcp:write' => [],
        'mcp:generate' => [CheddiPermissionEnumeration::GENERATION],
        'mcp:translate' => [CheddiPermissionEnumeration::TRANSLATION],
        'mcp:image' => [CheddiPermissionEnumeration::IMAGE_GENERATION],
        'mcp:media' => [CheddiPermissionEnumeration::MEDIA_UPLOAD],
    ];

    /**
     * @var array<string, list<string>>
     */
    private const TOOL_PERMISSIONS = [
        'readRenderedPage' => [CheddiPermissionEnumeration::RENDERED_PAGE_READ],
        'auditSeo' => [CheddiPermissionEnumeration::AUDIT],
        'auditAccessibility' => [CheddiPermissionEnumeration::AUDIT],
        'auditContent' => [CheddiPermissionEnumeration::AUDIT],
        'translatePage' => [CheddiPermissionEnumeration::PAGE_TRANSLATION],
        'translateFileMetadata' => [CheddiPermissionEnumeration::FILE_METADATA_TRANSLATION],
    ];

    public function scopePermissions(string $scope): ?array
    {
        return self::SCOPE_PERMISSIONS[$scope] ?? null;
    }

    public function toolPermissions(string $toolName): array
    {
        return self::TOOL_PERMISSIONS[$toolName] ?? [];
    }
}
