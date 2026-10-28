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

use AutoDudes\Cheddi\Exception\DocumentNotReadableException;
use Composer\Pcre\Preg;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory as SpreadsheetIOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpWord\IOFactory as WordIOFactory;
use PhpOffice\PhpWord\Reader\AbstractReader as AbstractWordReader;
use Psr\Log\LoggerInterface;
use Smalot\PdfParser\Config as PdfConfig;
use Smalot\PdfParser\Parser as PdfParser;
use TYPO3\CMS\Core\Resource\File;

class DocumentExtractorService
{
    public const MAX_OUTPUT_CHARS = 50000;

    public const MAX_SHEET_ROWS = 2000;

    public const MAX_SHEET_COLUMNS = 50;

    private const MAX_UNCOMPRESSED_BYTES = 200 * 1024 * 1024;

    private const MAX_ARCHIVE_ENTRIES = 10000;

    private const MAX_PDF_STREAM_BYTES = 16 * 1024 * 1024;

    private const FORMAT_TEXT = 'text';

    private const FORMAT_PDF = 'pdf';

    private const FORMAT_WORD = 'word';

    private const FORMAT_SPREADSHEET = 'spreadsheet';

    private const FORMAT_REQUIREMENTS = [
        self::FORMAT_PDF => [
            'class' => PdfParser::class,
            'extensions' => ['iconv', 'mbstring', 'zlib'],
        ],
        self::FORMAT_WORD => [
            'class' => WordIOFactory::class,
            'extensions' => ['dom', 'gd', 'json', 'xml', 'zip'],
        ],
        self::FORMAT_SPREADSHEET => [
            'class' => SpreadsheetIOFactory::class,
            'extensions' => ['ctype', 'dom', 'fileinfo', 'gd', 'iconv', 'libxml', 'mbstring', 'simplexml', 'xml', 'xmlreader', 'xmlwriter', 'zip', 'zlib'],
        ],
    ];

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @return array{text: string, truncated: bool}
     *
     * @throws DocumentNotReadableException
     */
    public function extract(File $file, int $charOffset = 0): array
    {
        $extension = strtolower($file->getExtension());

        $text = match ($this->formatOf($extension)) {
            self::FORMAT_TEXT => $this->readPlainText($file),
            self::FORMAT_PDF => $this->readPdf($file),
            self::FORMAT_WORD => $this->readWord($file, $extension),
            self::FORMAT_SPREADSHEET => $this->readSpreadsheet($file, $extension),
            default => throw new DocumentNotReadableException(sprintf('Files of type "%s" cannot be read as text.', $extension)),
        };

        return $this->capOutput($this->normalizeWhitespace($text), $charOffset);
    }

    public function canExtract(string $extension): bool
    {
        return match ($format = $this->formatOf($extension)) {
            self::FORMAT_TEXT => true,
            null => false,
            default => null === $this->unavailabilityReason($format),
        };
    }

    /**
     * @return array<string, string>
     */
    public function unavailableFormats(): array
    {
        $reasons = [];
        foreach (array_keys(self::FORMAT_REQUIREMENTS) as $format) {
            $reason = $this->unavailabilityReason($format);
            if (null !== $reason) {
                $reasons[$format] = $reason;
            }
        }

        return $reasons;
    }

    protected function isPhpExtensionLoaded(string $extension): bool
    {
        return extension_loaded($extension);
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

        if ($zip->numFiles > self::MAX_ARCHIVE_ENTRIES) {
            $entries = $zip->numFiles;
            $zip->close();
            $this->logger->warning('ChEddi: rejected a document with an implausible number of archive entries', [
                'entries' => $entries,
            ]);

            throw new DocumentNotReadableException(sprintf('This %s is too large to read safely.', $label));
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

            throw new DocumentNotReadableException(sprintf('This %s is too large to read safely.', $label));
        }
    }

    protected function maxUncompressedBytes(): int
    {
        return self::MAX_UNCOMPRESSED_BYTES;
    }

    private function unavailabilityReason(string $format): ?string
    {
        $requirements = self::FORMAT_REQUIREMENTS[$format];
        if (!class_exists($requirements['class'])) {
            return 'the library is not installed';
        }

        $missing = array_values(array_filter(
            $requirements['extensions'],
            fn (string $extension): bool => !$this->isPhpExtensionLoaded($extension),
        ));

        return [] === $missing ? null : sprintf('the PHP extension(s) %s are missing', implode(', ', $missing));
    }

    private function assertFormatAvailable(string $format, string $label): void
    {
        $reason = $this->unavailabilityReason($format);
        if (null !== $reason) {
            throw new DocumentNotReadableException(sprintf('%s cannot be read: %s.', $label, $reason));
        }
    }

    private function loadPcreWhereTheCoreLacksIt(): void
    {
        if (class_exists(Preg::class)) {
            return;
        }

        // Classic mode on cores that ship no composer/pcre (v12, v13 before 13.4.35), see Decisions.md
        $autoloader = dirname(__DIR__, 3).'/Resources/Private/PHP/Compat/ComposerVendor/autoload.php';
        if (is_file($autoloader)) {
            require_once $autoloader;
        }
    }

    private function formatOf(string $extension): ?string
    {
        return match (strtolower($extension)) {
            'txt', 'csv', 'md', 'json', 'xml' => self::FORMAT_TEXT,
            'pdf' => self::FORMAT_PDF,
            'docx', 'doc', 'odt', 'rtf' => self::FORMAT_WORD,
            'xlsx', 'xls', 'ods' => self::FORMAT_SPREADSHEET,
            default => null,
        };
    }

    private function readPlainText(File $file): string
    {
        return $file->getContents();
    }

    private function readPdf(File $file): string
    {
        $this->assertFormatAvailable(self::FORMAT_PDF, 'PDF files');

        try {
            $config = new PdfConfig();
            $config->setDecodeMemoryLimit(self::MAX_PDF_STREAM_BYTES);

            return (new PdfParser([], $config))->parseContent($file->getContents())->getText();
        } catch (\Throwable $e) {
            $this->logger->warning('ChEddi: could not extract text from a PDF', [
                'file' => $file->getUid(),
                'exception' => $e->getMessage(),
            ]);

            throw new DocumentNotReadableException('This PDF could not be read. It may be encrypted or contain only scanned images.');
        }
    }

    private function readWord(File $file, string $extension): string
    {
        $this->assertFormatAvailable(self::FORMAT_WORD, 'Word documents');

        return $this->withTemporaryCopy($file, function (string $path) use ($extension): string {
            $reader = match ($extension) {
                'doc' => 'MsDoc',
                'odt' => 'ODText',
                'rtf' => 'RTF',
                default => 'Word2007',
            };

            $wordReader = WordIOFactory::createReader($reader);
            if (!$wordReader instanceof AbstractWordReader) {
                throw new \RuntimeException(sprintf('The %s reader cannot switch off image loading.', $reader));
            }
            $document = $wordReader->setImageLoading(false)->load($path);

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
        $this->assertFormatAvailable(self::FORMAT_SPREADSHEET, 'Spreadsheets');
        $this->loadPcreWhereTheCoreLacksIt();

        return $this->withTemporaryCopy($file, static function (string $path): string {
            $filter = new class implements IReadFilter {
                public bool $cutOff = false;

                public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
                {
                    $inside = $row <= DocumentExtractorService::MAX_SHEET_ROWS
                        && Coordinate::columnIndexFromString($columnAddress) <= DocumentExtractorService::MAX_SHEET_COLUMNS;
                    $this->cutOff = $this->cutOff || !$inside;

                    return $inside;
                }
            };

            $reader = SpreadsheetIOFactory::createReaderForFile($path);
            $reader->setReadDataOnly(true);
            $reader->setReadFilter($filter);
            $spreadsheet = $reader->load($path);

            $text = '';
            foreach ($spreadsheet->getAllSheets() as $sheet) {
                $text .= '## '.$sheet->getTitle()."\n";
                foreach ($sheet->toArray(null, false, false, false) as $row) {
                    $cells = array_map(static fn (mixed $cell): string => null === $cell ? '' : (string) $cell, $row);
                    $line = trim(implode(' | ', $cells), ' |');
                    if ('' !== $line) {
                        $text .= $line."\n";
                    }
                }
            }

            if ($filter->cutOff) {
                $text .= sprintf(
                    "\n[Only the first %d rows and %d columns of each sheet were read.]\n",
                    DocumentExtractorService::MAX_SHEET_ROWS,
                    DocumentExtractorService::MAX_SHEET_COLUMNS,
                );
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

            throw new DocumentNotReadableException(sprintf('This %s could not be read.', $label));
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
