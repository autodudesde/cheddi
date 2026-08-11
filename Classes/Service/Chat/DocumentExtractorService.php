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

namespace AutoDudes\Cheddi\Service\Chat;

use PhpOffice\PhpSpreadsheet\IOFactory as SpreadsheetIOFactory;
use PhpOffice\PhpWord\IOFactory as WordIOFactory;
use Psr\Log\LoggerInterface;
use Smalot\PdfParser\Parser as PdfParser;
use TYPO3\CMS\Core\Resource\File;

class DocumentExtractorService
{
    public const MAX_OUTPUT_CHARS = 50000;

    private const MAX_UNCOMPRESSED_BYTES = 200 * 1024 * 1024;

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @return array{text: string, truncated: bool}
     *
     * @throws \RuntimeException
     */
    public function extract(File $file, int $charOffset = 0): array
    {
        $extension = strtolower($file->getExtension());

        $text = match ($extension) {
            'txt', 'csv', 'md', 'json', 'xml' => $this->readPlainText($file),
            'pdf' => $this->readPdf($file),
            'docx', 'doc', 'odt', 'rtf' => $this->readWord($file, $extension),
            'xlsx', 'xls', 'ods' => $this->readSpreadsheet($file, $extension),
            default => throw new \RuntimeException(sprintf('Files of type "%s" cannot be read as text.', $extension)),
        };

        return $this->capOutput($this->normalizeWhitespace($text), $charOffset);
    }

    public function canExtract(string $extension): bool
    {
        return match (strtolower($extension)) {
            'txt', 'csv', 'md', 'json', 'xml' => true,
            'pdf' => class_exists(PdfParser::class),
            'docx', 'doc', 'odt', 'rtf' => class_exists(WordIOFactory::class),
            'xlsx', 'xls', 'ods' => class_exists(SpreadsheetIOFactory::class),
            default => false,
        };
    }

    protected function assertNotAZipBomb(string $path, string $label): void
    {
        if (!class_exists(\ZipArchive::class)) {
            return;
        }

        $zip = new \ZipArchive();
        if (true !== $zip->open($path)) {
            return;
        }

        $uncompressed = 0;
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $stat = $zip->statIndex($i);
            if (is_array($stat)) {
                $uncompressed += (int) ($stat['size'] ?? 0);
            }
        }
        $zip->close();

        if ($uncompressed > $this->maxUncompressedBytes()) {
            $this->logger->warning('ChEddi: rejected a document that decompresses to an implausible size', [
                'uncompressedBytes' => $uncompressed,
            ]);

            throw new \RuntimeException(sprintf('This %s is too large to read safely.', $label));
        }
    }

    protected function maxUncompressedBytes(): int
    {
        return self::MAX_UNCOMPRESSED_BYTES;
    }

    private function readPlainText(File $file): string
    {
        return $file->getContents();
    }

    private function readPdf(File $file): string
    {
        if (!class_exists(PdfParser::class)) {
            throw new \RuntimeException('PDF files cannot be read: the PDF library is not installed.');
        }

        try {
            return (new PdfParser())->parseContent($file->getContents())->getText();
        } catch (\Throwable $e) {
            $this->logger->warning('ChEddi: could not extract text from a PDF', [
                'file' => $file->getUid(),
                'exception' => $e->getMessage(),
            ]);

            throw new \RuntimeException('This PDF could not be read. It may be encrypted or contain only scanned images.');
        }
    }

    private function readWord(File $file, string $extension): string
    {
        if (!class_exists(WordIOFactory::class)) {
            throw new \RuntimeException('Word documents cannot be read: the Office library is not installed.');
        }

        return $this->withTemporaryCopy($file, function (string $path) use ($extension): string {
            $reader = match ($extension) {
                'doc' => 'MsDoc',
                'odt' => 'ODText',
                'rtf' => 'RTF',
                default => 'Word2007',
            };

            $document = WordIOFactory::createReader($reader)->load($path);

            $text = '';
            foreach ($document->getSections() as $section) {
                $text .= $this->collectWordText($section->getElements());
            }

            return $text;
        }, 'Word document');
    }

    /**
     * @param array<int, object> $elements
     */
    private function collectWordText(array $elements): string
    {
        $text = '';
        foreach ($elements as $element) {
            if (method_exists($element, 'getText')) {
                $value = $element->getText();
                if (is_string($value)) {
                    $text .= $value."\n";

                    continue;
                }
            }
            if (method_exists($element, 'getElements')) {
                $text .= $this->collectWordText($element->getElements());
            }
        }

        return $text;
    }

    private function readSpreadsheet(File $file, string $extension): string
    {
        if (!class_exists(SpreadsheetIOFactory::class)) {
            throw new \RuntimeException('Spreadsheets cannot be read: the spreadsheet library is not installed.');
        }

        return $this->withTemporaryCopy($file, static function (string $path): string {
            $reader = SpreadsheetIOFactory::createReaderForFile($path);
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($path);

            $text = '';
            foreach ($spreadsheet->getAllSheets() as $sheet) {
                $text .= '## '.$sheet->getTitle()."\n";
                foreach ($sheet->toArray(null, true, false, false) as $row) {
                    $cells = array_map(static fn (mixed $cell): string => null === $cell ? '' : (string) $cell, $row);
                    $line = trim(implode(' | ', $cells), ' |');
                    if ('' !== $line) {
                        $text .= $line."\n";
                    }
                }
            }

            return $text;
        }, 'spreadsheet');
    }

    /**
     * @param callable(string): string $reader
     */
    private function withTemporaryCopy(File $file, callable $reader, string $label): string
    {
        $path = $file->getForLocalProcessing(false);
        $this->assertNotAZipBomb($path, $label);

        try {
            return $reader($path);
        } catch (\Throwable $e) {
            $this->logger->warning('ChEddi: could not extract text from a document', [
                'file' => $file->getUid(),
                'exception' => $e->getMessage(),
            ]);

            throw new \RuntimeException(sprintf('This %s could not be read.', $label));
        }
    }

    private function normalizeWhitespace(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = (string) preg_replace('/[ \t]+/', ' ', $text);
        $text = (string) preg_replace('/\n{3,}/', "\n\n", $text);

        return trim($text);
    }

    /**
     * @return array{text: string, truncated: bool}
     */
    private function capOutput(string $text, int $charOffset): array
    {
        if ($charOffset > 0) {
            $text = mb_substr($text, $charOffset);
        }

        if (mb_strlen($text) <= self::MAX_OUTPUT_CHARS) {
            return ['text' => $text, 'truncated' => false];
        }

        $slice = mb_substr($text, 0, self::MAX_OUTPUT_CHARS);
        $lastBreak = mb_strrpos($slice, "\n");
        if (false !== $lastBreak && $lastBreak > (int) (self::MAX_OUTPUT_CHARS / 2)) {
            $slice = mb_substr($slice, 0, $lastBreak);
        }

        return ['text' => $slice, 'truncated' => true];
    }
}
