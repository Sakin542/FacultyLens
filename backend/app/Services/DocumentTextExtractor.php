<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpWord\Element\AbstractContainer;
use PhpOffice\PhpWord\Element\Table;
use PhpOffice\PhpWord\Element\Text;
use PhpOffice\PhpWord\Element\TextRun;
use PhpOffice\PhpWord\Element\Title;
use PhpOffice\PhpWord\IOFactory as WordIOFactory;
use Smalot\PdfParser\Parser as PdfParser;

class DocumentTextExtractor
{
    protected DocumentTextCleaner $cleaner;

    public function __construct(DocumentTextCleaner $cleaner)
    {
        $this->cleaner = $cleaner;
    }

    /**
     * Extract raw and cleaned text from a given file path based on extension/mime type.
     *
     * @param string $absoluteFilePath
     * @param string $extension
     * @return array{raw_text: string, cleaned_text: string}
     * @throws Exception
     */
    public function extract(string $absoluteFilePath, string $extension): array
    {
        if (!file_exists($absoluteFilePath)) {
            throw new Exception("File does not exist: {$absoluteFilePath}");
        }

        $extension = strtolower($extension);
        $rawText = '';

        switch ($extension) {
            case 'pdf':
                $rawText = $this->extractPdf($absoluteFilePath);
                break;

            case 'docx':
                $rawText = $this->extractDocx($absoluteFilePath);
                break;

            case 'txt':
                $rawText = $this->extractTxt($absoluteFilePath);
                break;

            default:
                throw new Exception("Unsupported file extension: .{$extension}. Allowed types: pdf, docx, txt.");
        }

        if (trim($rawText) === '') {
            throw new Exception("Document is empty or contains no readable text.");
        }

        $cleanedText = $this->cleaner->clean($rawText);

        return [
            'raw_text' => $rawText,
            'cleaned_text' => $cleanedText,
        ];
    }

    /**
     * Extract PDF text page-by-page (STEP 32 chunking). Returns [] on failure so callers
     * can fall back to the page-less text already stored on the document.
     *
     * @return array<int, string> 1-based page number => page text
     */
    public function extractPdfPages(string $filePath): array
    {
        try {
            $parser = new PdfParser();
            $pdf = $parser->parseFile($filePath);
            $pages = [];
            foreach ($pdf->getPages() as $i => $page) {
                $pages[$i + 1] = (string) $page->getText();
            }

            return $pages;
        } catch (Exception $e) {
            Log::warning("PDF per-page extraction failed for {$filePath}: " . $e->getMessage());

            return [];
        }
    }

    /**
     * Extract text from a PDF file using smalot/pdfparser.
     */
    protected function extractPdf(string $filePath): string
    {
        try {
            $parser = new PdfParser();
            $pdf = $parser->parseFile($filePath);
            $text = $pdf->getText();

            return (string) $text;
        } catch (Exception $e) {
            Log::error("PDF Extraction failed for {$filePath}: " . $e->getMessage());
            throw new Exception("Failed to extract text from PDF: " . $e->getMessage());
        }
    }

    /**
     * Extract text from a DOCX file using PhpWord with fallback to XML parsing.
     */
    protected function extractDocx(string $filePath): string
    {
        $extractedText = '';

        try {
            // Attempt extraction using PhpWord
            $phpWord = WordIOFactory::load($filePath, 'Word2007');
            $lines = [];

            foreach ($phpWord->getSections() as $section) {
                $this->extractElementsFromContainer($section, $lines);
            }

            $extractedText = implode("\n", $lines);
        } catch (Exception $e) {
            Log::warning("PhpWord load failed for {$filePath}, attempting XML fallback: " . $e->getMessage());
            // Fallback: unzip and parse word/document.xml directly
            $extractedText = $this->extractDocxViaZipXml($filePath);
        }

        // If PhpWord returned empty string, try XML fallback as well
        if (trim($extractedText) === '') {
            $extractedText = $this->extractDocxViaZipXml($filePath);
        }

        return $extractedText;
    }

    /**
     * Recursively extract text from PhpWord containers, paragraphs, and tables.
     */
    protected function extractElementsFromContainer(AbstractContainer $container, array &$lines): void
    {
        foreach ($container->getElements() as $element) {
            if ($element instanceof Text) {
                $lines[] = $element->getText();
            } elseif ($element instanceof TextRun) {
                $runText = '';
                foreach ($element->getElements() as $runElement) {
                    if ($runElement instanceof Text) {
                        $runText .= $runElement->getText();
                    } elseif (method_exists($runElement, 'getText')) {
                        $runText .= $runElement->getText();
                    }
                }
                if ($runText !== '') {
                    $lines[] = $runText;
                }
            } elseif ($element instanceof Title) {
                $lines[] = $element->getText();
            } elseif ($element instanceof Table) {
                foreach ($element->getRows() as $row) {
                    $rowTexts = [];
                    foreach ($row->getCells() as $cell) {
                        $cellLines = [];
                        $this->extractElementsFromContainer($cell, $cellLines);
                        $rowTexts[] = implode(' ', $cellLines);
                    }
                    $lines[] = implode(' | ', $rowTexts);
                }
            } elseif ($element instanceof AbstractContainer) {
                $this->extractElementsFromContainer($element, $lines);
            } elseif (method_exists($element, 'getText')) {
                $lines[] = $element->getText();
            }
        }
    }

    /**
     * Fallback DOCX extractor that reads word/document.xml from the zip archive.
     */
    protected function extractDocxViaZipXml(string $filePath): string
    {
        $zip = new \ZipArchive();
        if ($zip->open($filePath) === true) {
            $xmlIndex = $zip->locateName('word/document.xml');
            if ($xmlIndex !== false) {
                $xmlData = $zip->getFromIndex($xmlIndex);
                $zip->close();

                if ($xmlData) {
                    // Replace paragraph closing tags and break tags with newlines
                    $xmlData = preg_replace('/<\/w:p>/', "\n", $xmlData);
                    $xmlData = preg_replace('/<w:br\/>/', "\n", $xmlData);
                    $xmlData = preg_replace('/<w:tab\/>/', "\t", $xmlData);
                    // Strip all remaining XML tags
                    $text = strip_tags($xmlData);
                    return html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
                }
            } else {
                $zip->close();
            }
        }

        return '';
    }

    /**
     * Extract text from a TXT file with UTF-8 normalization.
     */
    protected function extractTxt(string $filePath): string
    {
        $content = file_get_contents($filePath);
        if ($content === false) {
            throw new Exception("Failed to read TXT file: {$filePath}");
        }

        // Detect and convert encoding to UTF-8 if needed
        $encoding = mb_detect_encoding($content, ['UTF-8', 'ASCII', 'ISO-8859-1', 'Windows-1252'], true);
        if ($encoding && $encoding !== 'UTF-8') {
            $content = mb_convert_encoding($content, 'UTF-8', $encoding);
        }

        return $content;
    }
}

