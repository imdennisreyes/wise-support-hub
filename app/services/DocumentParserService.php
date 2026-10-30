<?php

declare(strict_types=1);

/**
 * DocumentParserService
 *
 * Extracts plain text from Word (.docx) and PDF files.
 *
 * DOCX  — uses PHP's built-in ZipArchive + DOMDocument (no external deps).
 * PDF   — delegates to the `pdftotext` CLI tool (poppler-utils package).
 *
 * Returns an array:
 *   title       string   First meaningful line / heading from the document
 *   description string   Remaining body text (HTML <p> formatted)
 *   filename    string   Original uploaded filename
 */
final class DocumentParserService
{
    // Maximum characters kept for description (MEDIUMTEXT in MySQL = 16 MB,
    // but we cap early to keep articles readable)
    private const MAX_BODY_CHARS = 200_000;

    // ─── Public entry point ───────────────────────────────────────────────────

    public static function parse(string $tmpPath, string $originalName): array
    {
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        return match ($ext) {
            'docx'  => self::parseDocx($tmpPath, $originalName),
            'pdf'   => self::parsePdf($tmpPath, $originalName),
            default => throw new RuntimeException(
                "Unsupported file type: .{$ext}. Only .docx and .pdf are accepted."
            ),
        };
    }

    // ─── DOCX parser ─────────────────────────────────────────────────────────

    private static function parseDocx(string $tmpPath, string $originalName): array
    {
        $zip = new ZipArchive();
        $result = $zip->open($tmpPath);

        if ($result !== true) {
            throw new RuntimeException(
                'Could not open the .docx file (ZipArchive error ' . $result . '). The file may be corrupted.'
            );
        }

        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        if ($xml === false) {
            throw new RuntimeException(
                'word/document.xml was not found inside the .docx file. The file may be invalid.'
            );
        }

        return self::buildFromParagraphs(
            self::extractDocxParagraphs($xml),
            $originalName
        );
    }

    /**
     * Walk the Word XML and return one plain-text string per paragraph.
     */
    private static function extractDocxParagraphs(string $xml): array
    {
        $doc = new DOMDocument();
        @$doc->loadXML($xml, LIBXML_NOERROR | LIBXML_NOWARNING);

        $ns = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
        $paragraphs = [];

        /** @var DOMElement $p */
        foreach ($doc->getElementsByTagNameNS($ns, 'p') as $p) {
            $text = '';

            // Collect all <w:t> run text nodes inside this paragraph
            /** @var DOMElement $t */
            foreach ($p->getElementsByTagNameNS($ns, 't') as $t) {
                $text .= $t->textContent;
            }

            $text = trim($text);
            if ($text !== '') {
                $paragraphs[] = $text;
            }
        }

        return $paragraphs;
    }

    // ─── PDF parser ───────────────────────────────────────────────────────────

    private static function parsePdf(string $tmpPath, string $originalName): array
    {
        if (!self::commandExists('pdftotext')) {
            throw new RuntimeException(
                'PDF parsing requires poppler-utils (pdftotext). ' .
                'Please contact your administrator to enable PDF support.'
            );
        }

        // -layout preserves column structure better; - means output to stdout
        $cmd    = 'pdftotext -layout ' . escapeshellarg($tmpPath) . ' - 2>&1';
        $output = shell_exec($cmd);

        if ($output === null || trim($output) === '') {
            throw new RuntimeException(
                'No text could be extracted from the PDF. ' .
                'The file may be a scanned image or protected against copying.'
            );
        }

        // Split into non-empty lines and treat each as a paragraph
        $lines = array_values(
            array_filter(
                array_map('trim', explode("\n", $output)),
                fn($l) => $l !== ''
            )
        );

        return self::buildFromParagraphs($lines, $originalName);
    }

    // ─── Shared helpers ───────────────────────────────────────────────────────

    /**
     * Turn a flat list of paragraph strings into a title + HTML description.
     */
    private static function buildFromParagraphs(array $paragraphs, string $filename): array
    {
        // Remove completely empty strings (already done above, but be safe)
        $paragraphs = array_values(array_filter($paragraphs, fn($p) => trim($p) !== ''));

        if (empty($paragraphs)) {
            throw new RuntimeException(
                'No readable text was found in the document. ' .
                'Make sure the file is not empty or entirely image-based.'
            );
        }

        // First paragraph → article title (trimmed to 255 chars)
        $title = mb_substr($paragraphs[0], 0, 255);

        // Remaining paragraphs → body, wrapped in <p> tags for the rich editor
        $bodyParts = array_slice($paragraphs, 1);
        if (empty($bodyParts)) {
            // Single-paragraph documents: use same text for both fields
            $bodyParts = [$title];
        }

        $html = implode('', array_map(fn($p) => '<p>' . htmlspecialchars($p, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>', $bodyParts));
        $description = mb_substr($html, 0, self::MAX_BODY_CHARS);

        return [
            'title'       => $title,
            'description' => $description,
            'filename'    => $filename,
        ];
    }

    private static function commandExists(string $command): bool
    {
        $check = shell_exec('which ' . escapeshellarg($command) . ' 2>/dev/null');
        return !empty(trim((string) $check));
    }
}
