<?php

declare(strict_types=1);

defined('TYPO3') || exit('Access denied.');

$cheddiLll = 'LLL:EXT:cheddi/Resources/Private/Language/locallang_tca.xlf:cheddi.permissions.';

$GLOBALS['TYPO3_CONF_VARS']['BE']['customPermOptions']['tx_cheddi_features'] = [
    'header' => $cheddiLll.'headerFeatures',
    'items' => [
        'enable_cheddi_interface' => [$cheddiLll.'enableChatInterface', 'tx-cheddi', $cheddiLll.'enableChatInterfaceDescription'],
        'enable_web_research' => [$cheddiLll.'enableWebResearch', 'tx-cheddi', $cheddiLll.'enableWebResearchDescription'],
        'enable_cheddi_generation' => [$cheddiLll.'enableGeneration', 'tx-cheddi', $cheddiLll.'enableGenerationDescription'],
        'enable_cheddi_translation' => [$cheddiLll.'enableTranslation', 'tx-cheddi', $cheddiLll.'enableTranslationDescription'],
        'enable_cheddi_page_translation' => [$cheddiLll.'enablePageTranslation', 'tx-cheddi', $cheddiLll.'enablePageTranslationDescription'],
        'enable_cheddi_file_metadata_translation' => [$cheddiLll.'enableFileMetadataTranslation', 'tx-cheddi', $cheddiLll.'enableFileMetadataTranslationDescription'],
        'enable_cheddi_image_generation' => [$cheddiLll.'enableImageGeneration', 'tx-cheddi', $cheddiLll.'enableImageGenerationDescription'],
        'enable_cheddi_audit' => [$cheddiLll.'enableAudit', 'tx-cheddi', $cheddiLll.'enableAuditDescription'],
        'enable_cheddi_rendered_page_read' => [$cheddiLll.'enableRenderedPageRead', 'tx-cheddi', $cheddiLll.'enableRenderedPageReadDescription'],
        'enable_cheddi_media_upload' => [$cheddiLll.'enableMediaUpload', 'tx-cheddi', $cheddiLll.'enableMediaUploadDescription'],
        'enable_cheddi_open_items' => [$cheddiLll.'enableOpenItems', 'tx-cheddi', $cheddiLll.'enableOpenItemsDescription'],
        'enable_cheddi_open_item_rules' => [$cheddiLll.'enableOpenItemRules', 'tx-cheddi', $cheddiLll.'enableOpenItemRulesDescription'],
    ],
];

$GLOBALS['TYPO3_CONF_VARS']['BE']['customPermOptions']['tx_cheddi_models'] = [
    'header' => $cheddiLll.'headerModels',
    'items' => [
        'OpenAiLuna' => [$cheddiLll.'modelOpenAiLuna', 'tx-cheddi', $cheddiLll.'modelChatDescription'],
        'IonosQwen35' => [$cheddiLll.'modelIonosQwen35', 'tx-cheddi', $cheddiLll.'modelChatDescription'],
        'IonosQwen38' => [$cheddiLll.'modelIonosQwen38', 'tx-cheddi', $cheddiLll.'modelChatDescription'],
        'ClaudeHaiku55' => [$cheddiLll.'modelClaudeHaiku55', 'tx-cheddi', $cheddiLll.'modelChatDescription'],
        'ClaudeSonnet55' => [$cheddiLll.'modelClaudeSonnet55', 'tx-cheddi', $cheddiLll.'modelChatDescription'],
    ],
];

unset($cheddiLll);
