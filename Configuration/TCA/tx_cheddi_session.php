<?php

/*
 *
 * This file is part of the "cheddi" Extension for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 *
 */

return [
    'ctrl' => [
        'title' => 'LLL:EXT:cheddi/Resources/Private/Language/locallang_tca.xlf:cheddi.tca.session',
        'label' => 'title',
        'label_alt' => 'session_uuid',
        'label_alt_force' => true,
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'delete' => 'deleted',
        'default_sortby' => 'last_activity DESC',
        'hideTable' => true,
        'adminOnly' => true,
        'rootLevel' => -1,
        'security' => [
            'ignorePageTypeRestriction' => true,
        ],
    ],
    'types' => [
        '1' => [
            'showitem' => 'title, session_uuid, be_user, model, last_activity',
        ],
    ],
    'columns' => [
        'title' => [
            'label' => 'LLL:EXT:core/Resources/Private/Language/locallang_general.xlf:LGL.title',
            'config' => [
                'type' => 'input',
                'size' => 60,
                'max' => 255,
                'readOnly' => true,
            ],
        ],
        'session_uuid' => [
            'label' => 'LLL:EXT:cheddi/Resources/Private/Language/locallang_tca.xlf:cheddi.tca.session.sessionUuid',
            'config' => [
                'type' => 'input',
                'size' => 40,
                'max' => 36,
                'readOnly' => true,
            ],
        ],
        'be_user' => [
            'label' => 'LLL:EXT:cheddi/Resources/Private/Language/locallang_tca.xlf:cheddi.tca.session.beUser',
            'config' => [
                'type' => 'number',
                'readOnly' => true,
            ],
        ],
        'model' => [
            'label' => 'LLL:EXT:cheddi/Resources/Private/Language/locallang_tca.xlf:cheddi.tca.session.model',
            'config' => [
                'type' => 'input',
                'size' => 30,
                'max' => 64,
                'readOnly' => true,
            ],
        ],
        'last_activity' => [
            'label' => 'LLL:EXT:cheddi/Resources/Private/Language/locallang_tca.xlf:cheddi.tca.session.lastActivity',
            'config' => [
                'type' => 'datetime',
                'format' => 'datetime',
                'readOnly' => true,
            ],
        ],
    ],
];
