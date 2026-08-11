<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Imaging\IconProvider\BitmapIconProvider;

return [
    'tx-cheddi' => [
        'provider' => BitmapIconProvider::class,
        'source' => 'EXT:cheddi/Resources/Public/Icons/cheddi.png',
    ],
];
