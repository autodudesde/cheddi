<?php

declare(strict_types=1);

use AutoDudes\Cheddi\Controller\ModuleController;

return [
    'web_cheddi' => [
        'parent' => 'web',
        'workspaces' => '*',
        'path' => '/module/web/cheddi',
        'iconIdentifier' => 'tx-cheddi',
        'inheritNavigationComponentFromMainModule' => false,
        'component' => '@autodudes/cheddi/cheddi-module',
        'appearance' => [
            'renderInModuleMenu' => false,
        ],
        'labels' => 'LLL:EXT:cheddi/Resources/Private/Language/locallang_mod.xlf',
        'routes' => [
            '_default' => [
                'target' => ModuleController::class.'::handleRequest',
            ],
        ],
    ],
];
