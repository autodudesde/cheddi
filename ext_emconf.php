<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'ChEddi',
    'description' => 'AI assistant for the TYPO3 backend. A chat drawer on every backend page where editors work on pages, content, translations, metadata and other data in plain language.',
    'category' => 'be',
    'author' => 'AutoDudes',
    'state' => 'beta',
    'version' => '0.2.2',
    'constraints' => [
        'depends' => [
            'typo3' => '12.4.11-14.3.99',
            'ai_suite' => '12.23.0-14.99.99',
            'ai_suite_mcp' => '0.8.0-1.0.0',
            'workspaces' => '12.4.11-14.3.99',
            'scheduler' => '12.4.0-14.99.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
