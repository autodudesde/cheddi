<?php

declare(strict_types=1);

/*
 *
 * This file is part of the "cheddi" Extension for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 *
 */

namespace AutoDudes\Cheddi\Hook;

use AutoDudes\Cheddi\Service\Chat\ChatWriteCaptureService;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class ChatWriteCaptureHook
{
    /**
     * @param string       $status
     * @param string       $table
     * @param int|string   $recordUid
     * @param array<mixed> $fields
     */
    public function processDatamap_afterDatabaseOperations($status, $table, $recordUid, array $fields, DataHandler $dataHandler): void
    {
        $capture = GeneralUtility::makeInstance(ChatWriteCaptureService::class);
        if (!$capture->isActive()) {
            return;
        }

        if (isset($dataHandler->substNEWwithIDs[$recordUid])) {
            $recordUid = $dataHandler->substNEWwithIDs[$recordUid];
        }
        $uid = (int) $recordUid;
        if ($uid <= 0) {
            return;
        }

        $capture->capture((string) $table, $uid, 'new' === $status ? 'create' : 'update');
    }

    /**
     * @param int|string $id
     * @param mixed      $value
     * @param mixed      $pasteUpdate
     * @param mixed      $pasteDatamap
     */
    public function processCmdmap_postProcess(string $command, string $table, $id, $value, DataHandler $dataHandler, $pasteUpdate, $pasteDatamap): void
    {
        $capture = GeneralUtility::makeInstance(ChatWriteCaptureService::class);
        if (!$capture->isActive()) {
            return;
        }

        $uid = (int) $id;
        $action = 'update';

        switch ($command) {
            case 'delete':
                $action = 'delete';

                break;

            case 'move':
                $action = 'update';

                break;

            case 'copy':
            case 'localize':
                $action = 'create';
                $uid = (int) ($dataHandler->copyMappingArray[$table][$id] ?? 0);

                break;

            default:
                // version/other commands are handled via the datamap hook or not relevant.
                return;
        }

        if ($uid <= 0) {
            return;
        }

        $capture->capture($table, $uid, $action);
    }
}
