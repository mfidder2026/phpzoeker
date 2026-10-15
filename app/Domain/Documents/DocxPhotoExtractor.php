<?php

namespace App\Domain\Documents;

use DOMDocument;
use ZipArchive;

/**
 * Haalt de pasfoto uit een Fremen-CV: de eerste afbeelding in de hoofdtekst (het logo staat in
 * de koptekst en wordt overgeslagen). Gebeurt lokaal; de foto gaat nooit naar het taalmodel.
 */
class DocxPhotoExtractor
{
    /**
     * @return array{contents: string, extension: string}|null
     */
    public function extract(string $docxPath): ?array
    {
        $zip = new ZipArchive;

        if ($zip->open($docxPath) !== true) {
            return null;
        }

        $document = (string) $zip->getFromName('word/document.xml');
        $relations = (string) $zip->getFromName('word/_rels/document.xml.rels');

        // DrawingML (`a:blip r:embed`, gebruikelijk in Word) of VML (`v:imagedata r:id`, oudere documenten).
        if (preg_match('/<(?:a:blip[^>]*r:embed|v:imagedata[^>]*r:id)="([^"]+)"/', $document, $match) !== 1 || $relations === '') {
            $zip->close();

            return null;
        }

        $xml = new DOMDocument;
        $xml->loadXML($relations);
        $target = null;

        foreach ($xml->getElementsByTagName('Relationship') as $relation) {
            if ($relation->getAttribute('Id') === $match[1]) {
                $target = 'word/'.ltrim($relation->getAttribute('Target'), '/');
            }
        }

        $contents = $target !== null ? $zip->getFromName($target) : false;
        $zip->close();

        if ($target === null || $contents === false || $contents === '') {
            return null;
        }

        $extension = strtolower(pathinfo($target, PATHINFO_EXTENSION));

        return in_array($extension, ['jpg', 'jpeg', 'png'], true) ? ['contents' => $contents, 'extension' => $extension] : null;
    }
}
