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
use AutoDudes\Cheddi\Service\Chat\ChatGuidelinesService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

#[AsController]
final class PageReferenceController
{
    public function __construct(
        private readonly BackendUserService $backendUserService,
        private readonly ChatGuidelinesService $guidelinesService,
    ) {}

    public function titleAction(ServerRequestInterface $request): ResponseInterface
    {
        $denied = $this->guardPermission();
        if (null !== $denied) {
            return $denied;
        }

        $page = $this->readablePage($request);
        if (null === $page) {
            return $this->notFound();
        }

        return (new JsonResponse(['title' => (string) ($page['title'] ?? '')]))->withHeader('Cache-Control', 'no-store');
    }

    public function guidelinesAction(ServerRequestInterface $request): ResponseInterface
    {
        $denied = $this->guardPermission();
        if (null !== $denied) {
            return $denied;
        }

        $page = $this->readablePage($request);
        if (null === $page) {
            return $this->notFound();
        }

        return (new JsonResponse($this->guidelinesService->forPage((int) $page['uid'])))->withHeader('Cache-Control', 'no-store');
    }

    private function guardPermission(): ?ResponseInterface
    {
        if ($this->backendUserService->checkPermissions(CheddiPermissionEnumeration::INTERFACE)) {
            return null;
        }

        return new JsonResponse(
            ['error' => ['message' => 'Chat interface not enabled for this BE user group.', 'chatErrorCode' => 'featureDisabled']],
            403,
        );
    }

    /**
     * @return null|array<string, mixed>
     */
    private function readablePage(ServerRequestInterface $request): ?array
    {
        $pageId = (int) ($request->getQueryParams()['pageId'] ?? 0);
        $backendUser = $this->backendUserService->getBackendUser();
        if ($pageId <= 0 || null === $backendUser) {
            return null;
        }
        $page = BackendUtility::readPageAccess($pageId, $backendUser->getPagePermsClause(Permission::PAGE_SHOW));

        return is_array($page) ? $page : null;
    }

    private function notFound(): ResponseInterface
    {
        return new JsonResponse(['error' => ['message' => 'Page not found.']], 404);
    }
}
