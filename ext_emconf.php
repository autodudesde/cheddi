<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'ChEddi',
    'description' => 'AI assistant for the TYPO3 backend. A chat drawer on every backend page where editors work on pages, content, translations, metadata and other data in plain language.',
    'category' => 'be',
    'author' => 'AutoDudes',
    'state' => 'beta',
    'version' => '0.3.0',
    'constraints' => [
        'depends' => [
            'typo3' => '12.4.11-14.3.99',
            'ai_suite' => '12.24.0-14.99.99',
            'ai_suite_mcp' => '0.9.0-0.9.99',
            'workspaces' => '12.4.11-14.3.99',
            'reports' => '12.4.11-14.3.99',
            'scheduler' => '12.4.11-14.3.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
    'autoload' => [
        'psr-4' => [
            'AutoDudes\\Cheddi\\' => 'Classes/',
            'Complex\\' => 'Resources/Private/PHP/ComposerVendor/markbaker/complex/classes/src/',
            'Matrix\\' => 'Resources/Private/PHP/ComposerVendor/markbaker/matrix/classes/src/',
            'PhpOffice\\Math\\' => 'Resources/Private/PHP/ComposerVendor/phpoffice/math/src/Math/',
            'PhpOffice\\PhpSpreadsheet\\' => 'Resources/Private/PHP/ComposerVendor/phpoffice/phpspreadsheet/src/PhpSpreadsheet/',
            'PhpOffice\\PhpWord\\' => 'Resources/Private/PHP/ComposerVendor/phpoffice/phpword/src/PhpWord/',
            'Psr\\SimpleCache\\' => 'Resources/Private/PHP/ComposerVendor/psr/simple-cache/src/',
            'Smalot\\PdfParser\\' => 'Resources/Private/PHP/ComposerVendor/smalot/pdfparser/src/Smalot/PdfParser/',
            'ZipStream\\' => 'Resources/Private/PHP/ComposerVendor/maennchen/zipstream-php/src/',
        ],
    ],
];
