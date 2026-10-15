<?php

namespace App\Domain\Documents;

use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Shared\Converter;
use PhpOffice\PhpWord\SimpleType\Jc;
use PhpOffice\PhpWord\Style\Language;

/**
 * Bouwt de Fremen-CV-template (`resources/templates/cv/fremen-cv-nl.docx`) met de placeholders
 * uit Bijlage F. De opbouw volgt §12.1: kop met naam, titel, tagline, contactregel en foto;
 * profiel; kernexpertise in twee kolommen; werkervaring; projecten; opleidingen; eerdere
 * ervaring; talen en vaardigheden. Placeholders worden in één run geschreven (§12.4).
 */
class CvTemplateBuilder
{
    public const PRIMARY = '431858';

    public const MUTED = '6B5F74';

    /**
     * Placeholders die de template minimaal moet bevatten (gecontroleerd door een test).
     *
     * @var list<string>
     */
    public const REQUIRED_PLACEHOLDERS = [
        'naam', 'cv_titel', 'tagline', 'contactregel', 'extra_regel', 'foto', 'profieltekst',
        'ke_kop_1', 'ke_items_1', 'ke_kop_2', 'ke_items_2',
        'werkervaring', '/werkervaring', 'we_periode', 'we_functie', 'we_organisatie', 'we_subtitel', 'we_bullets', '/we_bullets', 'we_bullet', 'we_technologie',
        'projecten', '/projecten', 'pr_naam', 'pr_omschrijving', 'pr_technologie',
        'aansluiting', '/aansluiting', 'aa_eis', 'aa_invulling',
        'opl_periode', 'opl_naam',
        'ee_periode', 'ee_functie', 'ee_organisatie',
        'talen', 'vaardigheden', 'voetregel',
    ];

    public function build(string $path): void
    {
        $word = new PhpWord;
        $word->getSettings()->setThemeFontLang(new Language(Language::NL_NL));
        $word->setDefaultFontName('Ubuntu');
        $word->setDefaultFontSize(10);
        $word->setDefaultParagraphStyle(['spaceAfter' => Converter::pointToTwip(3), 'lineHeight' => 1.15]);

        $word->addFontStyle('name', ['size' => 22, 'bold' => true, 'color' => self::PRIMARY]);
        $word->addFontStyle('title', ['size' => 13, 'bold' => true, 'color' => '24132D']);
        $word->addFontStyle('muted', ['size' => 9, 'color' => self::MUTED]);
        $word->addFontStyle('heading', ['size' => 11, 'bold' => true, 'color' => self::PRIMARY, 'allCaps' => true]);
        $word->addFontStyle('strong', ['bold' => true]);
        $word->addParagraphStyle('headingPara', ['spaceBefore' => Converter::pointToTwip(12), 'spaceAfter' => Converter::pointToTwip(4), 'borderBottomSize' => 6, 'borderBottomColor' => self::PRIMARY]);
        $word->addParagraphStyle('bulletPara', ['indentation' => ['left' => 360, 'hanging' => 220], 'spaceAfter' => Converter::pointToTwip(1)]);

        $section = $word->addSection([
            'marginTop' => Converter::cmToTwip(1.6),
            'marginBottom' => Converter::cmToTwip(1.6),
            'marginLeft' => Converter::cmToTwip(1.9),
            'marginRight' => Converter::cmToTwip(1.9),
        ]);

        $footer = $section->addFooter();
        $footer->addText('${voetregel}', 'muted', ['alignment' => Jc::START]);
        $footer->addPreserveText('Fremen · pagina {PAGE} van {NUMPAGES}', 'muted', ['alignment' => Jc::END]);

        // Kop: tekst links, pasfoto rechts.
        $header = $section->addTable(['borderSize' => 0, 'cellMargin' => 0, 'width' => 100 * 50, 'unit' => 'pct']);
        $header->addRow();
        $left = $header->addCell(self::cm(13.5));
        $left->addText('${naam}', 'name', ['spaceAfter' => 0]);
        $left->addText('${cv_titel}', 'title', ['spaceAfter' => Converter::pointToTwip(2)]);
        $left->addText('${tagline}', ['italic' => true, 'color' => self::MUTED]);
        $left->addText('${contactregel}', 'muted');
        $left->addText('${extra_regel}', 'muted');
        $right = $header->addCell(self::cm(3.6), ['valign' => 'top']);
        $right->addText('${foto}', null, ['alignment' => Jc::END]);

        $this->heading($section, 'Professioneel profiel');
        $section->addText('${profieltekst}');

        $this->heading($section, 'Kernexpertise');
        $expertise = $section->addTable(['cellMargin' => 60]);
        $expertise->addRow();
        foreach ([1, 2] as $column) {
            $cell = $expertise->addCell(self::cm(8.6));
            $cell->addText('${ke_kop_'.$column.'}', 'strong', ['spaceAfter' => 0]);
            $cell->addText('${ke_items_'.$column.'}', 'muted');
        }

        $this->heading($section, 'Werkervaring');
        $section->addText('${werkervaring}');
        $section->addText('${we_periode}', 'muted', ['spaceBefore' => Converter::pointToTwip(6), 'spaceAfter' => 0]);
        $run = $section->addTextRun(['spaceAfter' => 0]);
        $run->addText('${we_functie}', 'strong');
        $run->addText(' — ');
        $run->addText('${we_organisatie}');
        $section->addText('${we_subtitel}', ['italic' => true, 'color' => self::MUTED]);
        $section->addText('${we_bullets}');
        $section->addText('•  ${we_bullet}', null, 'bulletPara');
        $section->addText('${/we_bullets}');
        $section->addText('${we_technologie}', 'muted');
        $section->addText('${/werkervaring}');

        $this->heading($section, 'Geselecteerde projecten');
        $section->addText('${projecten}');
        $section->addText('${pr_naam}', 'strong', ['spaceBefore' => Converter::pointToTwip(4), 'spaceAfter' => 0]);
        $section->addText('${pr_omschrijving}');
        $section->addText('${pr_technologie}', 'muted');
        $section->addText('${/projecten}');

        $section->addText('${aansluiting}');
        $this->heading($section, 'Aansluiting op de opdracht');
        $section->addText('${aa_eis}', 'strong', ['spaceAfter' => 0]);
        $section->addText('${aa_invulling}');
        $section->addText('${/aansluiting}');

        $this->heading($section, 'Opleidingen en certificaten');
        $education = $section->addTable(['cellMargin' => 40]);
        $education->addRow();
        $education->addCell(self::cm(3.4))->addText('${opl_periode}', 'muted');
        $education->addCell(self::cm(13.8))->addText('${opl_naam}');

        $this->heading($section, 'Eerdere ervaring en stages');
        $earlier = $section->addTable(['cellMargin' => 40]);
        $earlier->addRow();
        $earlier->addCell(self::cm(3.4))->addText('${ee_periode}', 'muted');
        $cell = $earlier->addCell(self::cm(13.8));
        $earlierRun = $cell->addTextRun();
        $earlierRun->addText('${ee_functie}', 'strong');
        $earlierRun->addText(' — ');
        $earlierRun->addText('${ee_organisatie}');

        $this->heading($section, 'Talen en vaardigheden');
        $section->addText('${talen}');
        $section->addText('${vaardigheden}', 'muted');

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }

        IOFactory::createWriter($word, 'Word2007')->save($path);
    }

    private static function cm(float $centimeters): int
    {
        return (int) round(Converter::cmToTwip($centimeters));
    }

    private function heading(Section $section, string $text): void
    {
        $section->addText($text, 'heading', 'headingPara');
    }
}
