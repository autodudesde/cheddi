<?php

declare(strict_types=1);

defined('TYPO3') || exit;

$table = 'tx_aisuite_domain_model_custom_prompt_template';
if (isset($GLOBALS['TCA'][$table]['columns']['scope']['config']['items'])
    && is_array($GLOBALS['TCA'][$table]['columns']['scope']['config']['items'])
) {
    $GLOBALS['TCA'][$table]['columns']['scope']['config']['items'][] = [
        'label' => 'LLL:EXT:cheddi/Resources/Private/Language/locallang_tca.xlf:cheddi.promptTemplate.scopeCheddi',
        'value' => 'cheddi',
    ];
}
unset($table);
