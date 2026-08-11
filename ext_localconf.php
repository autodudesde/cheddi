<?php

use AutoDudes\AiSuiteMcp\Mcp\Log\SensitiveDataProcessor;
use AutoDudes\Cheddi\Hook\ChatWriteCaptureHook;
use Psr\Log\LogLevel;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Log\Writer\FileWriter;
use TYPO3\CMS\Core\Utility\GeneralUtility;

defined('TYPO3') || exit('Access denied.');

$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass']['cheddi']
    = ChatWriteCaptureHook::class;
$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processCmdmapClass']['cheddi']
    = ChatWriteCaptureHook::class;

try {
    $aisuiteChatExtConf = GeneralUtility::makeInstance(ExtensionConfiguration::class)->get('cheddi');
} catch (Throwable) {
    $aisuiteChatExtConf = [];
}

try {
    $aisuiteChatMcpExtConf = GeneralUtility::makeInstance(ExtensionConfiguration::class)->get('ai_suite_mcp');
} catch (Throwable) {
    $aisuiteChatMcpExtConf = [];
}

$aisuiteChatAdditionalRedactionPatterns = array_values(array_unique(array_filter(
    array_map('trim', array_merge(
        explode(',', (string) ($aisuiteChatMcpExtConf['mcpLogRedactionPatterns'] ?? '')),
        explode(',', (string) ($aisuiteChatExtConf['chatLogRedactionPatterns'] ?? '')),
    )),
    static fn (string $entry): bool => '' !== $entry,
)));

$aisuiteChatLogVerbose = (string) ($aisuiteChatExtConf['chatLogVerbose'] ?? 'inherit');
if ('' === $aisuiteChatLogVerbose || 'inherit' === $aisuiteChatLogVerbose) {
    $aisuiteChatLogVerbose = (string) ($aisuiteChatMcpExtConf['mcpLogVerbose'] ?? '1');
}

$GLOBALS['TYPO3_CONF_VARS']['LOG']['AutoDudes']['Cheddi']['writerConfiguration'] = [
    LogLevel::WARNING => [
        FileWriter::class => [
            'logFile' => Environment::getVarPath().'/log/cheddi_warnings.log',
        ],
    ],
    LogLevel::INFO => [
        FileWriter::class => [
            'logFile' => Environment::getVarPath().'/log/cheddi.log',
            'disabled' => !(bool) (int) $aisuiteChatLogVerbose,
        ],
    ],
];

$GLOBALS['TYPO3_CONF_VARS']['LOG']['AutoDudes']['Cheddi']['processorConfiguration'] = [
    LogLevel::DEBUG => [
        SensitiveDataProcessor::class => [
            'additionalPatterns' => $aisuiteChatAdditionalRedactionPatterns,
        ],
    ],
];

unset($aisuiteChatExtConf, $aisuiteChatMcpExtConf, $aisuiteChatAdditionalRedactionPatterns, $aisuiteChatLogVerbose);
