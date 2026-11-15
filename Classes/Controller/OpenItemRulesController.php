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
use AutoDudes\Cheddi\Service\Chat\OpenItem\OpenItemRuleAccess;
use AutoDudes\Cheddi\Service\Chat\OpenItem\OpenItemRuleManager;
use AutoDudes\Cheddi\Service\Chat\OpenItem\OpenItemRulePreview;
use AutoDudes\Cheddi\Service\Chat\OpenItem\OpenItemRuleSuggestions;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\JsonResponse;

#[AsController]
final class OpenItemRulesController
{
    public function __construct(
        private readonly BackendUserService $backendUserService,
        private readonly OpenItemRuleAccess $access,
        private readonly OpenItemRuleManager $manager,
        private readonly OpenItemRulePreview $preview,
        private readonly OpenItemRuleSuggestions $suggestions,
    ) {}

    public function listAction(ServerRequestInterface $request): ResponseInterface
    {
        $backendUser = $this->managingUser();
        if (null === $backendUser) {
            return $this->denied();
        }

        return $this->fresh(['rules' => $this->manager->listFor($backendUser), 'isAdmin' => $backendUser->isAdmin()]);
    }

    public function optionsAction(ServerRequestInterface $request): ResponseInterface
    {
        $backendUser = $this->managingUser();
        if (null === $backendUser) {
            return $this->denied();
        }

        $query = $request->getQueryParams();
        $table = is_string($query['table'] ?? null) ? $query['table'] : '';
        if ('' !== $table) {
            return $this->fresh(['fields' => $this->manager->fieldsFor($backendUser, $table)]);
        }

        return $this->fresh($this->manager->optionsFor($backendUser, (int) ($query['pageId'] ?? 0)));
    }

    public function goalOptionsAction(ServerRequestInterface $request): ResponseInterface
    {
        $backendUser = $this->managingUser();
        if (null === $backendUser) {
            return $this->denied();
        }

        $query = $request->getQueryParams();
        $table = is_string($query['table'] ?? null) ? $query['table'] : '';
        if ('' !== $table) {
            return $this->fresh(['fields' => $this->manager->goalFieldsFor($backendUser, (string) ($query['goal'] ?? ''), $table)]);
        }

        return $this->fresh($this->manager->goalOptionsFor($backendUser, (int) ($query['pageId'] ?? 0)));
    }

    public function goalPreviewAction(ServerRequestInterface $request): ResponseInterface
    {
        $backendUser = $this->managingUser();
        if (null === $backendUser) {
            return $this->denied();
        }

        $answer = $this->manager->previewGoal($backendUser, $this->body($request), time());

        return null === $answer ? $this->denied() : $this->fresh($answer);
    }

    public function goalCreateAction(ServerRequestInterface $request): ResponseInterface
    {
        $backendUser = $this->managingUser();
        if (null === $backendUser) {
            return $this->denied();
        }

        $answer = $this->manager->createFromGoal($backendUser, $this->body($request), time());
        if (null === $answer) {
            return $this->denied();
        }
        if (isset($answer['problem'])) {
            return new JsonResponse(['error' => ['message' => $answer['problem'], 'chatErrorCode' => 'openItemRuleInvalid']], 400);
        }

        return new JsonResponse(['rule' => $answer['rule'] ?? null]);
    }

    public function previewAction(ServerRequestInterface $request): ResponseInterface
    {
        $backendUser = $this->managingUser();
        if (null === $backendUser) {
            return $this->denied();
        }

        $answer = $this->preview->preview($backendUser, $this->manager->normalize($this->body($request)), time());

        return null === $answer ? $this->denied() : $this->fresh($answer);
    }

    public function suggestionsAction(ServerRequestInterface $request): ResponseInterface
    {
        $backendUser = $this->managingUser();
        if (null === $backendUser) {
            return $this->denied();
        }

        return $this->fresh($this->suggestions->standardFor($backendUser, time()));
    }

    public function adoptAction(ServerRequestInterface $request): ResponseInterface
    {
        $backendUser = $this->managingUser();
        if (null === $backendUser) {
            return $this->denied();
        }
        $ids = $this->body($request)['ids'] ?? [];
        $ids = is_array($ids) ? array_values(array_filter($ids, 'is_string')) : [];

        return $this->fresh(['adopted' => $this->manager->adopt($backendUser, $ids, time())]);
    }

    public function saveAction(ServerRequestInterface $request): ResponseInterface
    {
        $backendUser = $this->managingUser();
        if (null === $backendUser || !$backendUser->isAdmin()) {
            return $this->denied();
        }

        $body = $this->body($request);
        $answer = $this->manager->save($backendUser, (int) ($body['uid'] ?? 0), $body, time());
        if (null === $answer) {
            return $this->denied();
        }
        if (isset($answer['problem'])) {
            return new JsonResponse(['error' => ['message' => $answer['problem'], 'chatErrorCode' => 'openItemRuleInvalid']], 400);
        }

        return new JsonResponse(['rule' => $answer['rule'] ?? null]);
    }

    public function toggleAction(ServerRequestInterface $request): ResponseInterface
    {
        $backendUser = $this->managingUser();
        if (null === $backendUser) {
            return $this->denied();
        }

        $body = $this->body($request);
        $rule = $this->manager->setActive($backendUser, (int) ($body['uid'] ?? 0), '1' === (string) ($body['active'] ?? ''), time());

        return null === $rule ? $this->notFound() : new JsonResponse(['rule' => $rule]);
    }

    public function deleteAction(ServerRequestInterface $request): ResponseInterface
    {
        $backendUser = $this->managingUser();
        if (null === $backendUser) {
            return $this->denied();
        }

        return $this->manager->delete($backendUser, (int) ($this->body($request)['uid'] ?? 0), time())
            ? new JsonResponse(['deleted' => true])
            : $this->notFound();
    }

    private function managingUser(): ?BackendUserAuthentication
    {
        $backendUser = $this->backendUserService->getBackendUser();
        if (null === $backendUser
            || !$this->backendUserService->checkPermissions(CheddiPermissionEnumeration::INTERFACE)
            || !$this->access->mayManage($backendUser)) {
            return null;
        }

        return $backendUser;
    }

    /**
     * @return array<string, mixed>
     */
    private function body(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();

        return is_array($body) ? $body : [];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function fresh(array $payload): ResponseInterface
    {
        return (new JsonResponse($payload))->withHeader('Cache-Control', 'no-store');
    }

    private function denied(): ResponseInterface
    {
        return new JsonResponse(['error' => ['message' => 'Managing open item checks is not enabled for this BE user group.', 'chatErrorCode' => 'featureDisabled']], 403);
    }

    private function notFound(): ResponseInterface
    {
        return new JsonResponse(['error' => ['message' => 'Open item check not found.', 'chatErrorCode' => 'openItemRuleNotFound']], 404);
    }
}
