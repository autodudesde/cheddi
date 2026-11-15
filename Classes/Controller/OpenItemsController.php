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
use AutoDudes\Cheddi\Service\Chat\OpenItem\Fix\OpenItemFixModels;
use AutoDudes\Cheddi\Service\Chat\OpenItem\Fix\OpenItemFixService;
use AutoDudes\Cheddi\Service\Chat\OpenItemService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Core\Http\JsonResponse;

#[AsController]
final class OpenItemsController
{
    public function __construct(
        private readonly BackendUserService $backendUserService,
        private readonly OpenItemService $openItemService,
        private readonly OpenItemFixModels $fixModels,
        private readonly OpenItemFixService $fixService,
    ) {}

    public function listAction(ServerRequestInterface $request): ResponseInterface
    {
        $denied = $this->guardPermission(CheddiPermissionEnumeration::OPEN_ITEMS, CheddiPermissionEnumeration::OPEN_ITEM_RULES);
        if (null !== $denied) {
            return $denied;
        }

        return (new JsonResponse($this->openItemService->forCurrentUser()))->withHeader('Cache-Control', 'no-store');
    }

    public function resolveAction(ServerRequestInterface $request): ResponseInterface
    {
        $denied = $this->guardPermission(CheddiPermissionEnumeration::OPEN_ITEMS);
        if (null !== $denied) {
            return $denied;
        }

        $body = $request->getParsedBody();
        $uid = is_array($body) ? (int) ($body['uid'] ?? 0) : 0;
        if ($uid <= 0 || !$this->openItemService->resolve($uid)) {
            return new JsonResponse(['error' => ['message' => 'Open item not found.', 'chatErrorCode' => 'openItemNotFound']], 404);
        }

        return new JsonResponse(['resolved' => true]);
    }

    public function fixModelsAction(ServerRequestInterface $request): ResponseInterface
    {
        $denied = $this->guardPermission(CheddiPermissionEnumeration::OPEN_ITEMS);
        if (null !== $denied) {
            return $denied;
        }

        return (new JsonResponse($this->fixModels->forCurrentUser() + ['estimatedTokens' => OpenItemFixModels::ESTIMATED_TOKENS]))->withHeader('Cache-Control', 'no-store');
    }

    public function suggestAction(ServerRequestInterface $request): ResponseInterface
    {
        $denied = $this->guardPermission(CheddiPermissionEnumeration::OPEN_ITEMS);
        if (null !== $denied) {
            return $denied;
        }

        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];
        $result = $this->fixService->suggest((int) ($body['uid'] ?? 0), (string) ($body['model'] ?? ''));

        return isset($result['error']) ? $this->fixError($result['error']) : new JsonResponse($result);
    }

    public function acceptAction(ServerRequestInterface $request): ResponseInterface
    {
        $denied = $this->guardPermission(CheddiPermissionEnumeration::OPEN_ITEMS);
        if (null !== $denied) {
            return $denied;
        }

        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];
        $result = $this->fixService->accept(
            $request,
            (int) ($body['uid'] ?? 0),
            (string) ($body['value'] ?? ''),
            (string) ($body['model'] ?? ''),
            (string) ($body['base'] ?? ''),
        );

        return isset($result['error']) ? $this->fixError($result['error']) : new JsonResponse(['accepted' => true]);
    }

    private function fixError(string $error): ResponseInterface
    {
        return match ($error) {
            'modelNotAllowed' => new JsonResponse(['error' => ['message' => 'This model may not be used here.', 'chatErrorCode' => 'fixModelNotAllowed']], 403),
            'noContent' => new JsonResponse(['error' => ['message' => 'There is no content to base a suggestion on.', 'chatErrorCode' => 'fixNoContent']], 422),
            'serverError' => new JsonResponse(['error' => ['message' => 'The suggestion could not be created.', 'chatErrorCode' => 'fixServerError']], 502),
            'emptyValue' => new JsonResponse(['error' => ['message' => 'An empty value is not written.', 'chatErrorCode' => 'fixEmptyValue']], 422),
            'changedMeanwhile' => new JsonResponse(['error' => ['message' => 'The field changed meanwhile.', 'chatErrorCode' => 'fixChangedMeanwhile']], 409),
            'writeFailed' => new JsonResponse(['error' => ['message' => 'The value could not be written.', 'chatErrorCode' => 'fixWriteFailed']], 500),
            default => new JsonResponse(['error' => ['message' => 'Open item not found.', 'chatErrorCode' => 'openItemNotFound']], 404),
        };
    }

    private function guardPermission(string ...$anyOf): ?ResponseInterface
    {
        if ($this->backendUserService->checkPermissions(CheddiPermissionEnumeration::INTERFACE)) {
            foreach ($anyOf as $permission) {
                if ($this->backendUserService->checkPermissions($permission)) {
                    return null;
                }
            }
        }

        return new JsonResponse(
            ['error' => ['message' => 'Open items are not enabled for this BE user group.', 'chatErrorCode' => 'featureDisabled']],
            403,
        );
    }
}
