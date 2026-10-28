<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

defined('TYPO3') || exit;

$lll = 'LLL:EXT:cheddi/Resources/Private/Language/locallang_tca.xlf:cheddi.beGroups.';
$inherit = ['label' => $lll.'inherit', 'value' => ''];

ExtensionManagementUtility::addTCAcolumns('be_groups', [
    'chatUploadFolder' => [
        'label' => $lll.'chatUploadFolder',
        'description' => $lll.'chatUploadFolder.description',
        'config' => [
            'type' => 'input',
            'eval' => 'trim',
            'max' => 255,
        ],
    ],
    'chatUploadRandomNames' => [
        'label' => $lll.'chatUploadRandomNames',
        'description' => $lll.'chatUploadRandomNames.description',
        'config' => [
            'type' => 'select',
            'renderType' => 'selectSingle',
            'items' => [$inherit, ['label' => $lll.'chatUploadRandomNames.on', 'value' => '1']],
        ],
    ],
    'chatForceGdpa' => [
        'label' => $lll.'chatForceGdpa',
        'description' => $lll.'chatForceGdpa.description',
        'config' => [
            'type' => 'select',
            'renderType' => 'selectSingle',
            'items' => [$inherit, ['label' => $lll.'chatForceGdpa.on', 'value' => '1']],
        ],
    ],
    'chatWriteMode' => [
        'label' => $lll.'chatWriteMode',
        'description' => $lll.'chatWriteMode.description',
        'config' => [
            'type' => 'select',
            'renderType' => 'selectSingle',
            'items' => [$inherit, ['label' => $lll.'chatWriteMode.workspace', 'value' => 'workspace']],
        ],
    ],
    'chatAllowRawHtmlWrite' => [
        'label' => $lll.'chatAllowRawHtmlWrite',
        'description' => $lll.'chatAllowRawHtmlWrite.description',
        'config' => [
            'type' => 'select',
            'renderType' => 'selectSingle',
            'items' => [$inherit, ['label' => $lll.'chatAllowRawHtmlWrite.off', 'value' => '0']],
        ],
    ],
    'chatExcludedTools' => [
        'label' => $lll.'chatExcludedTools',
        'description' => $lll.'chatExcludedTools.description',
        'config' => [
            'type' => 'text',
            'rows' => 2,
            'eval' => 'trim',
        ],
    ],
    'chatExcludedTables' => [
        'label' => $lll.'chatExcludedTables',
        'description' => $lll.'chatExcludedTables.description',
        'config' => [
            'type' => 'text',
            'rows' => 2,
            'eval' => 'trim',
        ],
    ],
]);

ExtensionManagementUtility::addToAllTCAtypes(
    'be_groups',
    '--div--;'.$lll.'tab, chatUploadFolder, chatUploadRandomNames, chatForceGdpa, chatWriteMode, chatAllowRawHtmlWrite, chatExcludedTools, chatExcludedTables',
    '',
    'after:mediaStorageFolder',
);

unset($lll, $inherit);
