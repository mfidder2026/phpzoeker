<?php

namespace App\Domain\Documents;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use RuntimeException;
use ZipArchive;

/**
 * Maakt de Fremen-CV-template uit een bestaand Fremen-CV (§12.4: "Maak uit het aangeleverde CV
 * van Mark één Fremen-CV-template met placeholders"). Opmaak, kop- en voettekst, marges en
 * lettertypes blijven exact gelijk; alle inhoud wordt vervangen door placeholders (Bijlage F),
 * elk in één run. De pasfoto en de metadata van de bron worden verwijderd, zodat de template
 * geen persoonsgegevens bevat.
 */
class CvTemplateFromSource
{
    private const W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    private const R = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    /**
     * Placeholders die de template minimaal moet bevatten (gecontroleerd door een test).
     *
     * @var list<string>
     */
    public const REQUIRED_PLACEHOLDERS = [
        'naam', 'cv_titel', 'tagline', 'contactregel', 'extra_regel', 'foto',
        'kop_profiel', 'kop_kernexpertise', 'kop_werkervaring', 'kop_projecten',
        'profiel', 'profiel_alinea', '/profiel', 'aa_rijen', '/aa_rijen',
        'ke_kop_1', 'ke_items_1', 'ke_kop_2', 'ke_items_2',
        'werkervaring', 'we_periode', 'we_functie', 'we_organisatie', 'we_subtitel', 'we_bullets', 'we_bullet', '/we_bullets', '/werkervaring',
        'projecten', 'pr_naam', 'pr_suffix', 'pr_omschrijving', 'pr_tech_label', 'pr_technologie', '/projecten',
        'aansluiting', 'aa_eis', 'aa_invulling', '/aansluiting',
        'extra_secties', 'es_kop', 'es_links', 'es_li', '/es_links', 'es_rechts', 'es_ri', '/es_rechts', '/extra_secties',
        'kop_opleidingen', 'opl_periode', 'opl_naam',
        'kop_eerdere_ervaring', 'ee_periode', 'ee_functie', 'ee_organisatie',
        'kop_talen', 'talen_blok', 'taal_naam', 'taal_niveau', '/talen_blok', 'vaardigheden_blok', 'vaardigheid', '/vaardigheden_blok',
        'voetregel',
    ];

    private DOMDocument $doc;

    private DOMXPath $xpath;

    public function build(string $sourceDocx, string $targetPath): void
    {
        if (! is_file($sourceDocx)) {
            throw new RuntimeException("Bron-CV niet gevonden: {$sourceDocx}");
        }

        if (! is_dir(dirname($targetPath))) {
            mkdir(dirname($targetPath), 0775, true);
        }

        copy($sourceDocx, $targetPath);

        $zip = new ZipArchive;

        if ($zip->open($targetPath) !== true) {
            throw new RuntimeException('Het bron-CV is geen geldig Word-document.');
        }

        $this->doc = new DOMDocument;
        $this->doc->preserveWhiteSpace = true;
        $this->doc->loadXML((string) $zip->getFromName('word/document.xml'));
        $this->xpath = new DOMXPath($this->doc);
        $this->xpath->registerNamespace('w', self::W);
        $this->xpath->registerNamespace('r', self::R);

        $photoRelationIds = $this->photoRelationIds();
        $this->rebuildBody();

        $zip->addFromString('word/document.xml', (string) $this->doc->saveXML());
        $this->removePhotos($zip, $photoRelationIds);
        $zip->addFromString('docProps/core.xml', $this->neutralCoreProperties());
        $zip->close();
    }

    private function rebuildBody(): void
    {
        $body = $this->elements('//w:body')[0] ?? null;

        if (! $body instanceof DOMElement) {
            throw new RuntimeException('Het bron-CV heeft geen hoofdtekst.');
        }

        /** @var list<DOMElement> $elements */
        $elements = [];

        foreach ($body->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $elements[] = $child;
            }
        }

        $sectPr = $this->last($elements, fn (DOMElement $e): bool => $e->localName === 'sectPr');
        $headerTable = $this->first($elements, fn (DOMElement $e): bool => $e->localName === 'tbl');
        $headingProto = $this->heading($elements, 'PROFESSIONEEL PROFIEL');
        $profileProto = $this->nextOf($elements, $headingProto, 'p');
        $expertiseTable = $this->nextOf($elements, $this->heading($elements, 'KERNEXPERTISE'), 'tbl');
        $workTable = $this->nextOf($elements, $this->heading($elements, 'WERKERVARING'), 'tbl');
        $bulletProto = $this->nextOf($elements, $workTable, 'p');
        $projectsHeading = $this->heading($elements, 'GESELECTEERDE PROJECTEN');
        $projectTitle = $this->nextOf($elements, $projectsHeading, 'p');
        $projectDescription = $this->nextOf($elements, $projectTitle, 'p');
        $projectTechnology = $this->nextOf($elements, $projectDescription, 'p');
        $educationHeading = $this->heading($elements, 'OPLEIDINGEN');
        $educationTable = $this->nextOf($elements, $educationHeading, 'tbl');
        $earlierHeading = $this->heading($elements, 'EERDERE');
        $earlierTable = $this->nextOf($elements, $earlierHeading, 'tbl');
        $languagesHeading = $this->heading($elements, 'TALEN');
        $languagesTable = $this->nextOf($elements, $languagesHeading, 'tbl');
        $footerLine = $this->last($elements, fn (DOMElement $e): bool => $e->localName === 'p' && trim($e->textContent) !== '');
        $extraHeading = $this->first($elements, fn (DOMElement $e): bool => $this->isHeading($e)
            && ! $this->sameNode($e, $educationHeading) && $this->index($elements, $e) > $this->index($elements, $projectsHeading)
            && $this->index($elements, $e) < $this->index($elements, $educationHeading));
        $extraTable = $this->nextOf($elements, $extraHeading, 'tbl');

        $new = [];

        // Kop: naam, titel, tagline, contactregels en pasfoto.
        $header = $this->clone($headerTable);
        $cells = $this->cells($header);
        $leftParagraphs = $this->paragraphs($cells[0]);
        $this->setRuns($leftParagraphs[0], ['${naam}']);
        $this->setRuns($leftParagraphs[1], ['${cv_titel}']);
        $tagline = $this->firstNonEmptyAfter($leftParagraphs, 1);
        $this->setRuns($leftParagraphs[$tagline], ['${tagline}']);
        $this->setRuns($leftParagraphs[$tagline + 1], ['${contactregel}']);

        if (isset($leftParagraphs[$tagline + 2])) {
            $this->setRuns($leftParagraphs[$tagline + 2], ['${extra_regel}']);
        } else {
            $extra = $this->clone($leftParagraphs[$tagline + 1]);
            $this->setRuns($extra, ['${extra_regel}']);
            $cells[0]->appendChild($extra);
        }

        $this->setRuns($this->paragraphs($cells[1])[0], ['${foto}']);
        $new[] = $header;

        // Professioneel profiel: één alinea per alinea in het profiel.
        $new[] = $this->headingCopy($headingProto, '${kop_profiel}');
        $new[] = $this->marker('profiel');
        $new[] = $this->textCopy($profileProto, ['${profiel_alinea}']);
        $new[] = $this->marker('/profiel');

        // Kernexpertise in twee kolommen (cloneRow op ke_kop_1).
        $new[] = $this->headingCopy($headingProto, '${kop_kernexpertise}');
        $expertise = $this->firstRowOnly($this->clone($expertiseTable));
        $expertiseCells = $this->cells($expertise);
        foreach ([1, 2] as $column) {
            $paragraphs = $this->paragraphs($expertiseCells[$column - 1]);
            $this->setRuns($paragraphs[0], ['${ke_kop_'.$column.'}']);
            $this->setRuns($paragraphs[1], ['${ke_items_'.$column.'}']);
        }
        $new[] = $expertise;

        // Werkervaring: tabelregel met periode/functie/organisatie/subtitel + bullets.
        $new[] = $this->headingCopy($headingProto, '${kop_werkervaring}');
        $new[] = $this->marker('werkervaring');
        $work = $this->clone($workTable);
        // Breed genoeg voor "08-2025 – heden" (breedte uit het CV van Sten); samen even breed als de bron.
        $this->setColumnWidths($work, [1985, 8051]);
        $workCells = $this->cells($work);
        $this->setRuns($this->paragraphs($workCells[0])[0], ['${we_periode}']);
        $roleParagraphs = $this->paragraphs($workCells[1]);
        $this->setRuns($roleParagraphs[0], ['${we_functie}', '  |  ${we_organisatie}']);
        $this->setRuns($roleParagraphs[1], ['${we_subtitel}']);
        $new[] = $work;
        $new[] = $this->marker('we_bullets');
        $new[] = $this->textCopy($bulletProto, ['• ', '${we_bullet}']);
        $new[] = $this->marker('/we_bullets');
        $new[] = $this->spacer($profileProto);
        $new[] = $this->marker('/werkervaring');

        // Geselecteerde projecten.
        $new[] = $this->headingCopy($headingProto, '${kop_projecten}');
        $new[] = $this->marker('projecten');
        $new[] = $this->textCopy($projectTitle, ['${pr_naam}', '${pr_suffix}']);
        $new[] = $this->textCopy($projectDescription, ['${pr_omschrijving}']);
        $new[] = $this->textCopy($projectTechnology, ['${pr_tech_label}', '${pr_technologie}']);
        $new[] = $this->marker('/projecten');

        // Aansluiting op de opdracht (alleen bij een getailord CV).
        $new[] = $this->marker('aansluiting');
        $new[] = $this->headingCopy($headingProto, 'AANSLUITING OP DE OPDRACHT');
        $new[] = $this->marker('aa_rijen');
        $new[] = $this->textCopy($projectTitle, ['${aa_eis}']);
        $new[] = $this->textCopy($projectDescription, ['${aa_invulling}']);
        $new[] = $this->marker('/aa_rijen');
        $new[] = $this->marker('/aansluiting');

        // Aanvullende secties in twee kolommen met bullets (bijv. "AI-augmented engineering").
        $new[] = $this->marker('extra_secties');
        $new[] = $this->headingCopy($headingProto, '${es_kop}');
        $extra = $this->clone($extraTable);
        $extraCells = $this->cells($extra);
        foreach (['links' => 'es_li', 'rechts' => 'es_ri'] as $side => $item) {
            $cell = $extraCells[$side === 'links' ? 0 : 1];
            $proto = $this->paragraphs($cell)[0];
            $bullet = $this->textCopy($proto, ['• ', '${'.$item.'}']);
            $this->clearChildren($cell, 'p');
            $cell->appendChild($this->marker('es_'.$side));
            $cell->appendChild($bullet);
            $cell->appendChild($this->marker('/es_'.$side));
        }
        $new[] = $extra;
        $new[] = $this->marker('/extra_secties');

        // Opleidingen (cloneRow op opl_periode).
        $new[] = $this->headingCopy($educationHeading, '${kop_opleidingen}');
        $education = $this->firstRowOnly($this->clone($educationTable));
        $educationCells = $this->cells($education);
        $this->onlyFirstParagraph($educationCells[0], '${opl_periode}');
        $this->onlyFirstParagraph($educationCells[1], '${opl_naam}');
        $new[] = $education;

        // Eerdere werkervaring (cloneRow op ee_periode).
        $new[] = $this->headingCopy($earlierHeading, '${kop_eerdere_ervaring}');
        $earlier = $this->firstRowOnly($this->clone($earlierTable));
        $earlierCells = $this->cells($earlier);
        $this->onlyFirstParagraph($earlierCells[0], '${ee_periode}');
        $this->onlyFirstParagraph($earlierCells[1], '${ee_functie}');
        $this->onlyFirstParagraph($earlierCells[2], '${ee_organisatie}');
        $new[] = $earlier;

        // Talen en aanvullende vaardigheden in twee kolommen.
        $new[] = $this->headingCopy($languagesHeading, '${kop_talen}');
        $languages = $this->clone($languagesTable);
        $languageCells = $this->cells($languages);
        $languageProto = $this->paragraphs($languageCells[0])[0];
        $skillProto = $this->paragraphs($languageCells[1])[0];
        $languageLine = $this->textCopy($languageProto, ['${taal_naam}', '${taal_niveau}']);
        $skillLine = $this->textCopy($skillProto, ['• ', '${vaardigheid}']);
        $this->clearChildren($languageCells[0], 'p');
        $languageCells[0]->appendChild($this->marker('talen_blok'));
        $languageCells[0]->appendChild($languageLine);
        $languageCells[0]->appendChild($this->marker('/talen_blok'));
        $this->clearChildren($languageCells[1], 'p');
        $languageCells[1]->appendChild($this->marker('vaardigheden_blok'));
        $languageCells[1]->appendChild($skillLine);
        $languageCells[1]->appendChild($this->marker('/vaardigheden_blok'));
        $new[] = $languages;

        $new[] = $this->textCopy($footerLine, ['${voetregel}']);

        foreach ($elements as $element) {
            if (! $this->sameNode($element, $sectPr)) {
                $body->removeChild($element);
            }
        }

        foreach ($new as $node) {
            $body->insertBefore($node, $sectPr);
        }
    }

    /**
     * Vervangt de runs van een alinea door nieuwe runs met de opmaak van de oorspronkelijke runs:
     * tekst i krijgt de opmaak van run i (of van de laatste run).
     *
     * @param  list<string>  $texts
     */
    private function setRuns(DOMElement $paragraph, array $texts): void
    {
        $runs = [];

        foreach ($paragraph->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === 'r') {
                $runs[] = $child;
            }
        }

        $prototypes = array_values(array_filter($runs, fn (DOMElement $run): bool => trim($run->textContent) !== '' && $run->getElementsByTagNameNS(self::W, 'drawing')->length === 0));
        $prototypes = $prototypes === [] ? $runs : $prototypes;

        foreach (iterator_to_array($paragraph->childNodes) as $child) {
            if ($child instanceof DOMElement && $child->localName !== 'pPr') {
                $paragraph->removeChild($child);
            }
        }

        foreach ($texts as $index => $text) {
            $prototype = $prototypes[min($index, max(0, count($prototypes) - 1))] ?? null;
            $run = $this->doc->createElementNS(self::W, 'w:r');

            $properties = $prototype !== null ? ($this->elements('./w:rPr', $prototype)[0] ?? null) : null;
            if ($properties instanceof DOMNode) {
                $run->appendChild($properties->cloneNode(true));
            }

            $node = $this->doc->createElementNS(self::W, 'w:t');
            $node->setAttribute('xml:space', 'preserve');
            $node->appendChild($this->doc->createTextNode($text));
            $run->appendChild($node);
            $paragraph->appendChild($run);
        }
    }

    /**
     * @param  list<string>  $texts
     */
    private function textCopy(DOMElement $paragraph, array $texts): DOMElement
    {
        $copy = $this->clone($paragraph);
        $this->setRuns($copy, $texts);

        return $copy;
    }

    private function headingCopy(DOMElement $heading, string $text): DOMElement
    {
        return $this->textCopy($heading, [$text]);
    }

    private function spacer(DOMElement $paragraph): DOMElement
    {
        $copy = $this->clone($paragraph);
        $this->setRuns($copy, ['']);

        return $copy;
    }

    private function marker(string $name): DOMElement
    {
        $paragraph = $this->doc->createElementNS(self::W, 'w:p');
        $run = $this->doc->createElementNS(self::W, 'w:r');
        $text = $this->doc->createElementNS(self::W, 'w:t');
        $text->appendChild($this->doc->createTextNode('${'.$name.'}'));
        $run->appendChild($text);
        $paragraph->appendChild($run);

        return $paragraph;
    }

    private function clone(DOMElement $element): DOMElement
    {
        $copy = $element->cloneNode(true);

        if (! $copy instanceof DOMElement) {
            throw new RuntimeException('Kopiëren van een element is mislukt.');
        }

        // Bladwijzers en revisie-ID's uit de bron horen niet in de template.
        foreach (['bookmarkStart', 'bookmarkEnd', 'proofErr'] as $name) {
            foreach (iterator_to_array($copy->getElementsByTagNameNS(self::W, $name)) as $node) {
                $node->parentNode?->removeChild($node);
            }
        }

        return $copy;
    }

    /**
     * @param  list<int>  $widths  kolombreedtes in twips
     */
    private function setColumnWidths(DOMElement $table, array $widths): void
    {
        foreach ($this->xpath->query('./w:tblGrid/w:gridCol', $table) ?: [] as $index => $column) {
            if ($column instanceof DOMElement && isset($widths[$index])) {
                $column->setAttributeNS(self::W, 'w:w', (string) $widths[$index]);
            }
        }

        foreach ($this->elements('./w:tr', $table) as $row) {
            foreach ($this->elements('./w:tc/w:tcPr/w:tcW', $row) as $index => $width) {
                if (isset($widths[$index])) {
                    $width->setAttributeNS(self::W, 'w:w', (string) $widths[$index]);
                    $width->setAttributeNS(self::W, 'w:type', 'dxa');
                }
            }
        }
    }

    /**
     * @return list<DOMElement>
     */
    private function elements(string $expression, ?DOMNode $context = null): array
    {
        $nodes = $this->xpath->query($expression, $context);

        return $nodes === false ? [] : array_values(array_filter(iterator_to_array($nodes), fn (mixed $node): bool => $node instanceof DOMElement));
    }

    private function firstRowOnly(DOMElement $table): DOMElement
    {
        $rows = $this->elements('./w:tr', $table);

        foreach (array_slice($rows, 1) as $row) {
            $table->removeChild($row);
        }

        return $table;
    }

    private function onlyFirstParagraph(DOMElement $cell, string $text): void
    {
        $paragraphs = $this->paragraphs($cell);
        $this->setRuns($paragraphs[0], [$text]);

        foreach (array_slice($paragraphs, 1) as $paragraph) {
            $cell->removeChild($paragraph);
        }
    }

    private function clearChildren(DOMElement $parent, string $localName): void
    {
        foreach (iterator_to_array($parent->childNodes) as $child) {
            if ($child instanceof DOMElement && $child->localName === $localName) {
                $parent->removeChild($child);
            }
        }
    }

    /**
     * @return list<DOMElement>
     */
    private function cells(DOMElement $table): array
    {
        return array_values(array_filter(iterator_to_array($this->xpath->query('./w:tr[1]/w:tc', $table) ?: []), fn ($node): bool => $node instanceof DOMElement));
    }

    /**
     * @return list<DOMElement>
     */
    private function paragraphs(DOMElement $cell): array
    {
        return array_values(array_filter(iterator_to_array($this->xpath->query('./w:p', $cell) ?: []), fn ($node): bool => $node instanceof DOMElement));
    }

    /**
     * @param  list<DOMElement>  $paragraphs
     */
    private function firstNonEmptyAfter(array $paragraphs, int $index): int
    {
        for ($i = $index + 1; $i < count($paragraphs); $i++) {
            if (trim($paragraphs[$i]->textContent) !== '') {
                return $i;
            }
        }

        throw new RuntimeException('De kop van het bron-CV heeft geen tagline.');
    }

    private function isHeading(DOMElement $element): bool
    {
        if ($element->localName !== 'p') {
            return false;
        }

        $text = trim($element->textContent);

        return $text !== '' && $text === mb_strtoupper($text) && $this->elements('./w:pPr/w:pBdr', $element) !== [];
    }

    /**
     * @param  list<DOMElement>  $elements
     */
    private function heading(array $elements, string $startsWith): DOMElement
    {
        return $this->first($elements, fn (DOMElement $e): bool => $this->isHeading($e) && str_contains(trim($e->textContent), $startsWith));
    }

    /**
     * @param  list<DOMElement>  $elements
     */
    private function nextOf(array $elements, DOMElement $after, string $localName): DOMElement
    {
        $start = $this->index($elements, $after);

        for ($i = $start + 1; $i < count($elements); $i++) {
            if ($elements[$i]->localName === $localName) {
                return $elements[$i];
            }
        }

        throw new RuntimeException("Geen {$localName} gevonden na '".trim($after->textContent)."'.");
    }

    /**
     * @param  list<DOMElement>  $elements
     * @param  callable(DOMElement): bool  $test
     */
    private function first(array $elements, callable $test): DOMElement
    {
        foreach ($elements as $element) {
            if ($test($element)) {
                return $element;
            }
        }

        throw new RuntimeException('Het bron-CV heeft niet de verwachte Fremen-opbouw.');
    }

    /**
     * @param  list<DOMElement>  $elements
     * @param  callable(DOMElement): bool  $test
     */
    private function last(array $elements, callable $test): DOMElement
    {
        return $this->first(array_reverse($elements), $test);
    }

    /**
     * @param  list<DOMElement>  $elements
     */
    private function index(array $elements, DOMElement $element): int
    {
        foreach ($elements as $index => $candidate) {
            if ($this->sameNode($candidate, $element)) {
                return $index;
            }
        }

        return -1;
    }

    private function sameNode(DOMNode $a, DOMNode $b): bool
    {
        return $a->isSameNode($b);
    }

    /**
     * @return list<string>
     */
    private function photoRelationIds(): array
    {
        $ids = [];

        foreach ($this->xpath->query('//w:body//w:drawing//*[local-name()="blip"]') ?: [] as $blip) {
            if ($blip instanceof DOMElement) {
                $ids[] = $blip->getAttributeNS(self::R, 'embed');
            }
        }

        return array_values(array_filter($ids));
    }

    /**
     * @param  list<string>  $relationIds
     */
    private function removePhotos(ZipArchive $zip, array $relationIds): void
    {
        $relations = new DOMDocument;
        $relations->loadXML((string) $zip->getFromName('word/_rels/document.xml.rels'));

        foreach (iterator_to_array($relations->getElementsByTagName('Relationship')) as $relation) {
            if (in_array($relation->getAttribute('Id'), $relationIds, true)) {
                $zip->deleteName('word/'.$relation->getAttribute('Target'));
                $relation->parentNode?->removeChild($relation);
            }
        }

        $zip->addFromString('word/_rels/document.xml.rels', (string) $relations->saveXML());
    }

    private function neutralCoreProperties(): string
    {
        $now = now()->utc()->format('Y-m-d\TH:i:s\Z');

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            .'<dc:title>Curriculum Vitae</dc:title><dc:creator>Fremen IT Workers</dc:creator><cp:lastModifiedBy>PHP Zoeker</cp:lastModifiedBy>'
            .'<dcterms:created xsi:type="dcterms:W3CDTF">'.$now.'</dcterms:created><dcterms:modified xsi:type="dcterms:W3CDTF">'.$now.'</dcterms:modified>'
            .'</cp:coreProperties>';
    }
}
