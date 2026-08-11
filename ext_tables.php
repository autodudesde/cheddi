<?php

defined('TYPO3') || exit('Access denied.');

$GLOBALS['TYPO3_CONF_VARS']['BE']['customPermOptions']['tx_aisuite_features']['items']['enable_cheddi_interface'] = [
    'LLL:EXT:cheddi/Resources/Private/Language/locallang_tca.xlf:cheddi.permissions.enableChatInterface',
    'tx-aisuite-permissions',
    'LLL:EXT:cheddi/Resources/Private/Language/locallang_tca.xlf:cheddi.permissions.enableChatInterfaceDescription',
];

$GLOBALS['TYPO3_CONF_VARS']['BE']['customPermOptions']['tx_aisuite_features']['items']['enable_web_research'] = [
    'LLL:EXT:cheddi/Resources/Private/Language/locallang_tca.xlf:cheddi.permissions.enableWebResearch',
    'tx-aisuite-permissions',
    'LLL:EXT:cheddi/Resources/Private/Language/locallang_tca.xlf:cheddi.permissions.enableWebResearchDescription',
];

$chatModelLll = 'LLL:EXT:cheddi/Resources/Private/Language/locallang_tca.xlf:';
$GLOBALS['TYPO3_CONF_VARS']['BE']['customPermOptions']['tx_aisuite_models']['items']['OpenAiLuna'] = [
    $chatModelLll.'cheddi.permissions.modelOpenAiLuna',
    'tx-cheddi',
    $chatModelLll.'cheddi.permissions.modelChatDescription',
];
$GLOBALS['TYPO3_CONF_VARS']['BE']['customPermOptions']['tx_aisuite_models']['items']['IonosQwen35'] = [
    $chatModelLll.'cheddi.permissions.modelIonosQwen35',
    'tx-cheddi',
    $chatModelLll.'cheddi.permissions.modelChatDescription',
];
$GLOBALS['TYPO3_CONF_VARS']['BE']['customPermOptions']['tx_aisuite_models']['items']['ClaudeHaiku45'] = [
    $chatModelLll.'cheddi.permissions.modelClaudeHaiku45',
    'tx-cheddi',
    $chatModelLll.'cheddi.permissions.modelChatDescription',
];
$GLOBALS['TYPO3_CONF_VARS']['BE']['customPermOptions']['tx_aisuite_models']['items']['ClaudeSonnet5'] = [
    $chatModelLll.'cheddi.permissions.modelClaudeSonnet5',
    'tx-cheddi',
    $chatModelLll.'cheddi.permissions.modelChatDescription',
];
