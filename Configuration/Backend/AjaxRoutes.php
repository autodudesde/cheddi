<?php

declare(strict_types=1);

use AutoDudes\Cheddi\Controller\AttachmentController;
use AutoDudes\Cheddi\Controller\ChatController;
use AutoDudes\Cheddi\Controller\WorkspaceReviewController;

return [
    'cheddi_attachment_preflight' => [
        'path' => '/cheddi/attachment/preflight',
        'target' => AttachmentController::class.'::preflightAction',
    ],
    'cheddi_attachment_upload' => [
        'path' => '/cheddi/attachment/upload',
        'target' => AttachmentController::class.'::uploadAction',
    ],
    'cheddi_ws_changes' => [
        'path' => '/cheddi/workspace/changes',
        'target' => WorkspaceReviewController::class.'::listChangesAction',
    ],
    'cheddi_ws_publish' => [
        'path' => '/cheddi/workspace/publish',
        'target' => WorkspaceReviewController::class.'::publishAction',
    ],
    'cheddi_ws_discard' => [
        'path' => '/cheddi/workspace/discard',
        'target' => WorkspaceReviewController::class.'::discardAction',
    ],
    'cheddi_turn' => [
        'path' => '/cheddi/turn',
        'target' => ChatController::class.'::startTurnAction',
    ],
    'cheddi_turn_continue' => [
        'path' => '/cheddi/turn/continue',
        'target' => ChatController::class.'::continueTurnAction',
    ],
    'cheddi_confirm' => [
        'path' => '/cheddi/confirm',
        'target' => ChatController::class.'::applyConfirmationsAction',
    ],
    'cheddi_models' => [
        'path' => '/cheddi/models',
        'target' => ChatController::class.'::availableModelsAction',
    ],
    'cheddi_help' => [
        'path' => '/cheddi/help',
        'target' => ChatController::class.'::helpAction',
    ],
    'cheddi_templates' => [
        'path' => '/cheddi/templates',
        'target' => ChatController::class.'::availableTemplatesAction',
    ],
    'cheddi_languages' => [
        'path' => '/cheddi/languages',
        'target' => ChatController::class.'::availableLanguagesAction',
    ],
    'cheddi_sessions_list' => [
        'path' => '/cheddi/sessions',
        'target' => ChatController::class.'::listSessionsAction',
    ],
    'cheddi_session_load' => [
        'path' => '/cheddi/session/load',
        'target' => ChatController::class.'::loadSessionAction',
    ],
    'cheddi_session_delete' => [
        'path' => '/cheddi/session/delete',
        'target' => ChatController::class.'::deleteSessionAction',
    ],
];
