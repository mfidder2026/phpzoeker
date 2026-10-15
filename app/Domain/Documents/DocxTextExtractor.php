<?php

namespace App\Domain\Documents;

use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;
use ZipArchive;

/**
 * Zet een DOCX om naar platte tekst met behoud van alinea's en tabelcellen, als input voor de
 * CV-import (§12.1). Kop- en voetteksten worden meegenomen.
 */
class DocxTextExtractor
{
    private const W_NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    public function extract(string $path): string
    {
        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            throw new RuntimeException('Het bestand is geen geldig Word-document (.docx).');
        }

        $parts = [];

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = (string) $zip->getNameIndex($index);

            if (preg_match('#^word/(header\d*|document|footer\d*)\.xml$#', $name) === 1) {
                $parts[$name] = (string) $zip->getFromIndex($index);
            }
        }

        $zip->close();

        if (! isset($parts['word/document.xml'])) {
            throw new RuntimeException('Het Word-document bevat geen hoofdtekst.');
        }

        uksort($parts, fn (string $a, string $b): int => $this->order($a) <=> $this->order($b));

        $text = implode("\n", array_map(fn (string $xml): string => $this->xmlToText($xml), $parts));

        return trim((string) preg_replace("/\n{3,}/", "\n\n", $text));
    }

    private function order(string $name): int
    {
        return match (true) {
            str_contains($name, 'header') => 0,
            str_contains($name, 'document') => 1,
            default => 2,
        };
    }

    private function xmlToText(string $xml): string
    {
        $document = new DOMDocument;
        $document->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('w', self::W_NS);

        $bodies = $xpath->query('//w:body');
        $body = ($bodies !== false ? $bodies->item(0) : null) ?? $document->documentElement;

        return $body instanceof DOMElement ? $this->blockText($body, $xpath) : '';
    }

    private function blockText(DOMElement $element, DOMXPath $xpath): string
    {
        $lines = [];

        foreach ($element->childNodes as $child) {
            if (! $child instanceof DOMElement) {
                continue;
            }

            if ($child->localName === 'p') {
                $lines[] = $this->paragraphText($child, $xpath);
            } elseif ($child->localName === 'tbl') {
                foreach ($xpath->query('./w:tr', $child) ?: [] as $row) {
                    if (! $row instanceof DOMElement) {
                        continue;
                    }

                    $cells = [];

                    foreach ($xpath->query('./w:tc', $row) ?: [] as $cell) {
                        if ($cell instanceof DOMElement) {
                            $cells[] = trim(str_replace("\n", ' / ', $this->blockText($cell, $xpath)));
                        }
                    }

                    $lines[] = implode(' | ', array_filter($cells, fn (string $cell): bool => $cell !== ''));
                }
            } elseif (in_array($child->localName, ['sdt', 'sdtContent', 'customXml', 'txbxContent'], true)) {
                $lines[] = $this->blockText($child, $xpath);
            }
        }

        return implode("\n", $lines);
    }

    private function paragraphText(DOMElement $paragraph, DOMXPath $xpath): string
    {
        $text = '';

        foreach ($xpath->query('.//w:t|.//w:tab|.//w:br', $paragraph) ?: [] as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $text .= match ($node->localName) {
                't' => $node->textContent,
                'tab' => "\t",
                default => "\n",
            };
        }

        $numbering = $xpath->query('./w:pPr/w:numPr', $paragraph);
        $isBullet = $numbering !== false && $numbering->length > 0;

        return ($isBullet && trim($text) !== '' ? '• ' : '').$text;
    }
}
