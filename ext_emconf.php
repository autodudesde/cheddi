<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'ChEddi',
    'description' => 'Chat interface for EXT:ai_suite. Adds a conversational backend drawer that lets editors interact with AI Suite tools via natural language, powered by the MCP ToolRegistry.',
    'category' => 'be',
    'author' => 'AutoDudes',
    'state' => 'beta',
    'version' => '0.2.0',
    'constraints' => [
        'depends' => [
            'typo3' => '12.4.11-14.3.99',
            'ai_suite' => '12.22.0-14.99.99',
            'ai_suite_mcp' => '0.7.0-1.0.0',
            'workspaces' => '12.4.11-14.3.99',
            'scheduler' => '12.4.0-14.99.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
