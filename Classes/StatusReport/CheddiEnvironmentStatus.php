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

namespace AutoDudes\Cheddi\StatusReport;

use AutoDudes\Cheddi\Service\Chat\ChatRequestService;
use AutoDudes\Cheddi\Service\Chat\DocumentExtractorService;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Reports\Status;
use TYPO3\CMS\Reports\StatusProviderInterface;

class CheddiEnvironmentStatus implements StatusProviderInterface
{
    public function __construct(
        private readonly DocumentExtractorService $documentExtractor,
    ) {}

    /**
     * @return list<Status>
     */
    public function getStatus(): array
    {
        return [
            $this->documentReadingStatus(),
            $this->executionTimeStatus(),
        ];
    }

    public function getLabel(): string
    {
        return 'ChEddi Environment';
    }

    protected function maxExecutionTime(): int
    {
        return (int) ini_get('max_execution_time');
    }

    private function documentReadingStatus(): Status
    {
        $unavailable = $this->documentExtractor->unavailableFormats();
        if ([] === $unavailable) {
            return new Status(
                'ChEddi Attachments',
                'All document types readable',
                'PDF, Word and spreadsheet attachments can be read.',
                ContextualFeedbackSeverity::OK,
            );
        }

        $reasons = [];
        foreach ($unavailable as $format => $reason) {
            $reasons[] = sprintf('%s: %s', $format, $reason);
        }

        return new Status(
            'ChEddi Attachments',
            sprintf('%d document type(s) not readable', count($unavailable)),
            sprintf(
                'Editors can attach these files, but ChEddi cannot read their text (%s). '
                .'Classic mode installations ship the libraries in Resources/Private/PHP of cheddi; the PHP extensions have to be installed on the server.',
                implode('; ', $reasons),
            ),
            ContextualFeedbackSeverity::WARNING,
        );
    }

    private function executionTimeStatus(): Status
    {
        $limit = $this->maxExecutionTime();
        $required = ChatRequestService::REQUEST_TIMEOUT_SECONDS;

        if ($limit > 0 && $limit < $required) {
            return new Status(
                'ChEddi Request Duration',
                sprintf('max_execution_time is %d s', $limit),
                sprintf(
                    'A chat turn may wait up to %d seconds for the AI Suite Server. PHP stops the request after %d seconds, '
                    .'so long turns end in an error. Raise max_execution_time and the matching web server or proxy timeout to at least %d seconds.',
                    $required,
                    $limit,
                    $required,
                ),
                ContextualFeedbackSeverity::WARNING,
            );
        }

        return new Status(
            'ChEddi Request Duration',
            $limit > 0 ? sprintf('max_execution_time is %d s', $limit) : 'max_execution_time is unlimited',
            sprintf('PHP allows a chat turn the %d seconds it may need. Web server and proxy timeouts are not visible from here.', $required),
            ContextualFeedbackSeverity::OK,
        );
    }
}
