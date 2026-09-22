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

use AutoDudes\AiSuite\Events\CollectUsageStatisticsEvent;
use AutoDudes\Cheddi\Command\AutoDeleteChatSessionsCommand;
use AutoDudes\Cheddi\EventListener\CollectChatStatisticsListener;
use AutoDudes\Cheddi\EventListener\InjectChatDrawerListener;
use AutoDudes\Cheddi\EventListener\WorkspacePublishListener;
use AutoDudes\Cheddi\Mcp\Tool\CreateCsvDownloadTool;
use AutoDudes\Cheddi\Mcp\Tool\ReadAttachmentTextTool;
use AutoDudes\Cheddi\Mcp\Tool\ReadWebPageTool;
use AutoDudes\Cheddi\Mcp\Tool\SearchWebTool;
use AutoDudes\Cheddi\Service\Chat\ToolBridge;
use AutoDudes\Cheddi\Service\Chat\UploadedFileWriter;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use TYPO3\CMS\Backend\Controller\Event\AfterBackendPageRenderEvent;
use TYPO3\CMS\Workspaces\Event\AfterRecordPublishedEvent;

return static function (ContainerConfigurator $configurator): void {
    $services = $configurator->services();

    $services->defaults()
        ->private()
        ->autowire()
        ->autoconfigure()
    ;

    $services->load('AutoDudes\Cheddi\\', __DIR__.'/../Classes/')->exclude([
        __DIR__.'/../Classes/Domain/Model',
        __DIR__.'/../Classes/Mcp/Tool',
    ]);

    foreach ([ReadAttachmentTextTool::class, SearchWebTool::class, ReadWebPageTool::class, CreateCsvDownloadTool::class] as $chatTool) {
        $services->set($chatTool)
            ->autowire()
            ->autoconfigure(false)
            ->tag(ToolBridge::CHAT_TOOL_TAG)
        ;
    }

    $services->set(UploadedFileWriter::class)
        ->public()
    ;

    $services->set(InjectChatDrawerListener::class)
        ->tag('event.listener', [
            'identifier' => 'cheddi/inject-drawer',
            'event' => AfterBackendPageRenderEvent::class,
        ])
    ;

    $services->set(CollectChatStatisticsListener::class)
        ->tag('event.listener', [
            'identifier' => 'cheddi/collect-statistics',
            'event' => CollectUsageStatisticsEvent::class,
        ])
    ;

    $services->set(WorkspacePublishListener::class)
        ->tag('event.listener', [
            'identifier' => 'cheddi/workspace-publish',
            'event' => AfterRecordPublishedEvent::class,
        ])
    ;

    $services->set(AutoDeleteChatSessionsCommand::class)
        ->tag('console.command', [
            'command' => 'cheddi:auto-delete-sessions',
            'description' => 'Soft-/hard-delete expired chat sessions.',
            'schedulable' => true,
        ])
    ;
};
