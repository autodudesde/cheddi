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

namespace AutoDudes\Cheddi\Controller;

use AutoDudes\AiSuite\Service\BackendUserService;
use AutoDudes\Cheddi\Enumeration\CheddiPermissionEnumeration;
use AutoDudes\Cheddi\Service\Chat\ChatWorkspaceFollowService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Core\Http\JsonResponse;

#[AsController]
final class WorkspaceFollowController
{
    public function __construct(
        private readonly BackendUserService $backendUserService,
        private readonly ChatWorkspaceFollowService $followService,
    ) {}

    public function followAction(ServerRequestInterface $request): ResponseInterface
    {
        $backendUser = $this->backendUserService->getBackendUser();
        if (null === $backendUser || !$this->backendUserService->checkPermissions(CheddiPermissionEnumeration::INTERFACE)) {
            return new JsonResponse(
                ['error' => ['message' => 'Chat interface not enabled for this BE user group.', 'chatErrorCode' => 'featureDisabled']],
                403,
            );
        }

        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];

        return new JsonResponse($this->followService->follow(
            $backendUser,
            is_string($body['session'] ?? null) ? $body['session'] : '',
            is_string($body['workspace'] ?? null) ? $body['workspace'] : '',
        ));
    }
}
