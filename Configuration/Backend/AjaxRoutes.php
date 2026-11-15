<?php

declare(strict_types=1);

use AutoDudes\Cheddi\Controller\AttachmentController;
use AutoDudes\Cheddi\Controller\ChatController;
use AutoDudes\Cheddi\Controller\OpenItemRulesController;
use AutoDudes\Cheddi\Controller\OpenItemsController;
use AutoDudes\Cheddi\Controller\PageReferenceController;
use AutoDudes\Cheddi\Controller\WorkspaceFollowController;
use AutoDudes\Cheddi\Controller\WorkspaceReviewController;

return [
    'cheddi_attachment_upload' => [
        'path' => '/cheddi/attachment/upload',
        'methods' => ['POST'],
        'target' => AttachmentController::class.'::uploadAction',
    ],
    'cheddi_ws_follow' => [
        'path' => '/cheddi/workspace/follow',
        'methods' => ['POST'],
        'target' => WorkspaceFollowController::class.'::followAction',
    ],
    'cheddi_ws_changes' => [
        'path' => '/cheddi/workspace/changes',
        'target' => WorkspaceReviewController::class.'::listChangesAction',
    ],
    'cheddi_ws_publish' => [
        'path' => '/cheddi/workspace/publish',
        'methods' => ['POST'],
        'target' => WorkspaceReviewController::class.'::publishAction',
    ],
    'cheddi_ws_discard' => [
        'path' => '/cheddi/workspace/discard',
        'methods' => ['POST'],
        'target' => WorkspaceReviewController::class.'::discardAction',
    ],
    'cheddi_turn' => [
        'path' => '/cheddi/turn',
        'methods' => ['POST'],
        'target' => ChatController::class.'::startTurnAction',
    ],
    'cheddi_turn_continue' => [
        'path' => '/cheddi/turn/continue',
        'methods' => ['POST'],
        'target' => ChatController::class.'::continueTurnAction',
    ],
    'cheddi_turn_progress' => [
        'path' => '/cheddi/turn/progress',
        'target' => ChatController::class.'::turnProgressAction',
    ],
    'cheddi_summarize' => [
        'path' => '/cheddi/summarize',
        'methods' => ['POST'],
        'target' => ChatController::class.'::summarizeHistoryAction',
    ],
    'cheddi_confirm' => [
        'path' => '/cheddi/confirm',
        'methods' => ['POST'],
        'target' => ChatController::class.'::applyConfirmationsAction',
    ],
    'cheddi_download_csv' => [
        'path' => '/cheddi/download/csv',
        'target' => ChatController::class.'::downloadCsvAction',
    ],
    'cheddi_status' => [
        'path' => '/cheddi/status',
        'target' => ChatController::class.'::statusAction',
    ],
    'cheddi_page_title' => [
        'path' => '/cheddi/page/title',
        'target' => PageReferenceController::class.'::titleAction',
    ],
    'cheddi_page_guidelines' => [
        'path' => '/cheddi/page/guidelines',
        'target' => PageReferenceController::class.'::guidelinesAction',
    ],
    'cheddi_open_items' => [
        'path' => '/cheddi/open-items',
        'target' => OpenItemsController::class.'::listAction',
    ],
    'cheddi_open_item_resolve' => [
        'path' => '/cheddi/open-items/resolve',
        'methods' => ['POST'],
        'target' => OpenItemsController::class.'::resolveAction',
    ],
    'cheddi_open_item_fix_models' => [
        'path' => '/cheddi/open-items/fix/models',
        'target' => OpenItemsController::class.'::fixModelsAction',
    ],
    'cheddi_open_item_suggest' => [
        'path' => '/cheddi/open-items/fix/suggest',
        'methods' => ['POST'],
        'target' => OpenItemsController::class.'::suggestAction',
    ],
    'cheddi_open_item_accept' => [
        'path' => '/cheddi/open-items/fix/accept',
        'methods' => ['POST'],
        'target' => OpenItemsController::class.'::acceptAction',
    ],
    'cheddi_open_item_rules' => [
        'path' => '/cheddi/open-item-rules',
        'target' => OpenItemRulesController::class.'::listAction',
    ],
    'cheddi_open_item_rule_options' => [
        'path' => '/cheddi/open-item-rules/options',
        'target' => OpenItemRulesController::class.'::optionsAction',
    ],
    'cheddi_open_item_rule_preview' => [
        'path' => '/cheddi/open-item-rules/preview',
        'methods' => ['POST'],
        'target' => OpenItemRulesController::class.'::previewAction',
    ],
    'cheddi_open_item_rule_suggestions' => [
        'path' => '/cheddi/open-item-rules/suggestions',
        'target' => OpenItemRulesController::class.'::suggestionsAction',
    ],
    'cheddi_open_item_rule_adopt' => [
        'path' => '/cheddi/open-item-rules/adopt',
        'methods' => ['POST'],
        'target' => OpenItemRulesController::class.'::adoptAction',
    ],
    'cheddi_open_item_rule_goal_options' => [
        'path' => '/cheddi/open-item-rules/goal/options',
        'target' => OpenItemRulesController::class.'::goalOptionsAction',
    ],
    'cheddi_open_item_rule_goal_preview' => [
        'path' => '/cheddi/open-item-rules/goal/preview',
        'methods' => ['POST'],
        'target' => OpenItemRulesController::class.'::goalPreviewAction',
    ],
    'cheddi_open_item_rule_goal_create' => [
        'path' => '/cheddi/open-item-rules/goal/create',
        'methods' => ['POST'],
        'target' => OpenItemRulesController::class.'::goalCreateAction',
    ],
    'cheddi_open_item_rule_save' => [
        'path' => '/cheddi/open-item-rules/save',
        'methods' => ['POST'],
        'target' => OpenItemRulesController::class.'::saveAction',
    ],
    'cheddi_open_item_rule_toggle' => [
        'path' => '/cheddi/open-item-rules/toggle',
        'methods' => ['POST'],
        'target' => OpenItemRulesController::class.'::toggleAction',
    ],
    'cheddi_open_item_rule_delete' => [
        'path' => '/cheddi/open-item-rules/delete',
        'methods' => ['POST'],
        'target' => OpenItemRulesController::class.'::deleteAction',
    ],
    'cheddi_help' => [
        'path' => '/cheddi/help',
        'target' => ChatController::class.'::helpAction',
    ],
    'cheddi_sessions_list' => [
        'path' => '/cheddi/sessions',
        'target' => ChatController::class.'::listSessionsAction',
    ],
    'cheddi_session_load' => [
        'path' => '/cheddi/session/load',
        'target' => ChatController::class.'::loadSessionAction',
    ],
    'cheddi_session_rename' => [
        'path' => '/cheddi/session/rename',
        'methods' => ['POST'],
        'target' => ChatController::class.'::renameSessionAction',
    ],
    'cheddi_session_delete' => [
        'path' => '/cheddi/session/delete',
        'methods' => ['POST'],
        'target' => ChatController::class.'::deleteSessionAction',
    ],
];
