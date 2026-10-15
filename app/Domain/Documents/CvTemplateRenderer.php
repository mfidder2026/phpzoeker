<?php

namespace App\Domain\Documents;

use App\Domain\Candidates\Models\Candidate;
use App\Settings\CvSettings;
use Illuminate\Support\Carbon;
use PhpOffice\PhpWord\Settings as WordSettings;
use PhpOffice\PhpWord\TemplateProcessor;
use RuntimeException;

/**
 * Vult de Fremen-CV-template met een profiel (master of getailord, structuur van Bijlage D).
 * Met het master-profiel en de opties van {@see self::masterOptions()} reproduceert dit het
 * oorspronkelijke CV (fase 1).
 */
class CvTemplateRenderer
{
    /** Pasfoto zoals in het bron-CV: 4,04 × 4,04 cm. */
    private const PHOTO_PIXELS = 153;

    /** Taal van het CV dat wordt gemaakt (nl of en). */
    private string $language = 'nl';

    public function __construct(private readonly CvSettings $settings) {}

    /**
     * Template per taal; zonder Engelse template wordt de Nederlandse gebruikt met Engelse koppen
     * en vaste teksten (§12.5).
     */
    public static function templatePath(string $language = 'nl'): string
    {
        $path = resource_path("templates/cv/fremen-cv-{$language}.docx");

        return is_file($path) || $language === 'nl' ? $path : resource_path('templates/cv/fremen-cv-nl.docx');
    }

    /**
     * Vaste teksten in het CV per taal.
     *
     * @return array<string, string>
     */
    public static function labels(string $language): array
    {
        return $language === 'en' ? [
            'profile' => 'PROFESSIONAL PROFILE', 'expertise' => 'CORE EXPERTISE', 'experience' => 'WORK EXPERIENCE', 'projects' => 'SELECTED PROJECTS',
            'education' => 'Education & certificates', 'earlier' => 'Earlier work experience', 'languages' => 'Languages & additional skills',
            'contact' => 'Contact via Fremen', 'license' => 'Driving licence B', 'own_car' => ' / own car', 'available' => 'Available', 'from' => 'from',
            'hours' => 'hours per week', 'birth' => 'Date of birth', 'nationality' => 'Nationality', 'via' => 'Via', 'technology' => 'Technology: ',
        ] : [
            'profile' => 'PROFESSIONEEL PROFIEL', 'expertise' => 'KERNEXPERTISE', 'experience' => 'WERKERVARING', 'projects' => 'GESELECTEERDE PROJECTEN',
            'education' => 'Opleidingen & certificaten', 'earlier' => 'Eerdere werkervaring', 'languages' => 'Talen & aanvullende vaardigheden',
            'contact' => 'Contact via Fremen', 'license' => 'Rijbewijs B', 'own_car' => ' / eigen auto', 'available' => 'Beschikbaar', 'from' => 'vanaf',
            'hours' => 'uur per week', 'birth' => 'Geboortedatum', 'nationality' => 'Nationaliteit', 'via' => 'Via', 'technology' => 'Technologie: ',
        ];
    }

    /**
     * Opties waarmee het master-CV er net zo uitziet als het aangeleverde CV: contactgegevens
     * van de kandidaat, rijbewijs, nationaliteit en beschikbaarheid in de kop.
     *
     * @return array{contact_line: string, show_driving_license: bool, show_nationality: bool, show_availability: bool}
     */
    public static function masterOptions(): array
    {
        return ['contact_line' => 'candidate', 'show_driving_license' => true, 'show_nationality' => true, 'show_availability' => true];
    }

    /**
     * @param  array<string, mixed>  $profile  Bijlage D (+ optioneel `aansluiting_op_opdracht`)
     * @param  array{contact_line?: string, show_birth_date?: bool, show_city?: bool, show_driving_license?: bool, show_nationality?: bool, show_availability?: bool, footer?: string|null}  $options  privacy-opties per aanbieding (§12.6)
     */
    public function render(Candidate $candidate, array $profile, string $outputPath, array $options = [], string $language = 'nl'): string
    {
        $template = self::templatePath($language);
        $this->language = $language;
        $labels = self::labels($language);

        if (! is_file($template)) {
            throw new RuntimeException("CV-template ontbreekt: {$template}. Draai `php artisan cv:build-template <bron-cv.docx>`.");
        }

        WordSettings::setOutputEscapingEnabled(true);
        $processor = new TemplateProcessor($template);

        $headings = (array) ($profile['sectiekoppen'] ?? []);
        $upper = fn (mixed $value, string $default): string => mb_strtoupper(filled($value) ? (string) $value : $default);

        $processor->setValues([
            'naam' => mb_strtoupper((string) ($profile['naam'] ?? $candidate->name)),
            'cv_titel' => (string) ($profile['cv_titel'] ?? ''),
            'tagline' => (string) ($profile['tagline'] ?? ''),
            'contactregel' => $this->contactLine($candidate, $options),
            'extra_regel' => $this->extraLine($candidate, $options),
            'kop_profiel' => $labels['profile'],
            'kop_kernexpertise' => $labels['expertise'],
            'kop_werkervaring' => $labels['experience'],
            'kop_projecten' => $labels['projects'],
            'kop_opleidingen' => $upper($headings['opleidingen'] ?? null, $labels['education']),
            'kop_eerdere_ervaring' => $upper($headings['eerdere_ervaring'] ?? null, $labels['earlier']),
            'kop_talen' => $upper($headings['talen'] ?? null, $labels['languages']),
            'voetregel' => (string) ($options['footer'] ?? ($profile['voetregel'] ?? null) ?? $this->settings->footer_text),
        ]);

        $this->photo($processor, $candidate);
        $this->profileText($processor, (string) ($profile['profieltekst'] ?? ''));
        $this->expertise($processor, (array) ($profile['kernexpertise'] ?? []));
        $this->workExperience($processor, (array) ($profile['werkervaring'] ?? []));
        $this->projects($processor, (array) ($profile['projecten'] ?? []));
        $this->fit($processor, (array) ($profile['aansluiting_op_opdracht'] ?? []));
        $this->extraSections($processor, (array) ($profile['aanvullende_secties'] ?? []));
        $this->education($processor, (array) ($profile['opleidingen'] ?? []), (array) ($profile['certificaten'] ?? []));
        $this->earlierExperience($processor, (array) ($profile['eerdere_ervaring'] ?? []));
        $this->languagesAndSkills($processor, (array) ($profile['talen'] ?? []), (array) ($profile['overige_vaardigheden'] ?? []));

        if (! is_dir(dirname($outputPath))) {
            mkdir(dirname($outputPath), 0775, true);
        }

        $processor->saveAs($outputPath);

        if ($language === 'en' && ! is_file(resource_path('templates/cv/fremen-cv-en.docx'))) {
            self::translateFixedTexts($outputPath);
        }

        return $outputPath;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function contactLine(Candidate $candidate, array $options = []): string
    {
        $parts = [];

        if ($options['show_city'] ?? $this->settings->show_city) {
            $parts[] = (string) $candidate->city;
        }

        if (($options['contact_line'] ?? $this->settings->contact_line) === 'candidate') {
            $parts[] = (string) $candidate->phone;
            $parts[] = (string) $candidate->email;
        } else {
            $parts[] = self::labels($this->language)['contact'].': '.trim($this->settings->fremen_contact_name.' · '.$this->settings->fremen_contact_email.' '.$this->settings->fremen_contact_phone);
        }

        $showLicense = $options['show_driving_license'] ?? ($this->settings->show_driving_license === 'always');

        if ($showLicense && $candidate->driving_license) {
            $parts[] = self::labels($this->language)['license'].($candidate->own_transport ? self::labels($this->language)['own_car'] : '');
        }

        return implode(' | ', array_filter($parts, fn (string $part): bool => trim($part) !== ''));
    }

    /**
     * Nationaliteit alleen bij screening; geboortedatum alleen als dat expliciet aanstaat (R4).
     *
     * @param  array<string, mixed>  $options
     */
    public function extraLine(Candidate $candidate, array $options = []): string
    {
        $parts = [];

        if (($options['show_nationality'] ?? false) && filled($candidate->nationality)) {
            $parts[] = $this->language === 'en' ? 'Nationality: '.$candidate->nationality : self::nationality((string) $candidate->nationality);
        }

        if ($options['show_availability'] ?? true) {
            $labels = self::labels($this->language);
            $parts[] = $labels['available'].': '.($candidate->isAvailableImmediately() ? '' : $labels['from'].' '.$candidate->available_from?->format('d-m-Y').', ').$candidate->hours_per_week.' '.$labels['hours'];
        }

        if (($options['show_birth_date'] ?? $this->settings->show_birth_date) && filled($candidate->birth_date)) {
            $parts[] = self::labels($this->language)['birth'].': '.Carbon::parse($candidate->birth_date)->format('d-m-Y');
        }

        return implode(' | ', $parts);
    }

    /**
     * "Nederlands" → "Nederlandse nationaliteit"; een afwijkende vorm blijft "Nationaliteit: …".
     */
    public static function nationality(string $nationality): string
    {
        return preg_match('/(s|sch)$/u', $nationality) === 1 ? $nationality.'e nationaliteit' : 'Nationaliteit: '.$nationality;
    }

    private function photo(TemplateProcessor $processor, Candidate $candidate): void
    {
        $path = $candidate->photo_path !== null ? storage_path('app/private/'.$candidate->photo_path) : null;

        if ($path !== null && is_file($path)) {
            $processor->setImageValue('foto', ['path' => $path, 'width' => self::PHOTO_PIXELS, 'height' => self::PHOTO_PIXELS, 'ratio' => true]);

            return;
        }

        $processor->setValue('foto', '');
    }

    /**
     * Elke regel van het profiel wordt een eigen alinea.
     */
    private function profileText(TemplateProcessor $processor, string $text): void
    {
        $paragraphs = array_values(array_filter(array_map('trim', preg_split('/\R+/', $text) ?: []), fn (string $line): bool => $line !== ''));

        $processor->cloneBlock('profiel', 0, true, false, self::escape(array_map(fn (string $line): array => ['profiel_alinea' => $line], $paragraphs ?: [''])));
    }

    /**
     * Kernexpertise in twee kolommen: blokken om en om links en rechts (cloneRow).
     *
     * @param  array<int, mixed>  $blocks
     */
    private function expertise(TemplateProcessor $processor, array $blocks): void
    {
        $blocks = array_values(array_filter($blocks, 'is_array'));
        $rows = (int) max(1, ceil(count($blocks) / 2));
        $processor->cloneRow('ke_kop_1', $rows);

        for ($row = 1; $row <= $rows; $row++) {
            foreach ([1, 2] as $column) {
                $block = $blocks[($row - 1) * 2 + ($column - 1)] ?? null;

                $processor->setValue("ke_kop_{$column}#{$row}", is_array($block) ? (string) ($block['kop'] ?? '') : '');
                $processor->setValue("ke_items_{$column}#{$row}", is_array($block) ? self::expertiseItems((array) ($block['items'] ?? [])) : '');
            }
        }
    }

    /**
     * "Docker (sinds 2017) · Kubernetes (basis)".
     *
     * @param  array<int, mixed>  $items
     */
    public static function expertiseItems(array $items): string
    {
        return collect($items)->filter(fn (mixed $item): bool => is_array($item))->map(function (array $item): string {
            $details = array_filter([
                ! empty($item['niveau']) ? (string) $item['niveau'] : null,
                ! empty($item['sinds']) ? 'sinds '.$item['sinds'] : null,
            ]);

            return (string) $item['naam'].($details !== [] ? ' ('.implode(', ', $details).')' : '');
        })->implode(' · ');
    }

    /**
     * @param  array<int, mixed>  $entries
     */
    private function workExperience(TemplateProcessor $processor, array $entries): void
    {
        $entries = array_values(array_filter($entries, 'is_array'));
        $processor->cloneBlock('werkervaring', count($entries), true, true);

        foreach ($entries as $index => $entry) {
            $i = $index + 1;
            $subtitle = filled($entry['subtitel'] ?? null) ? (string) $entry['subtitel'] : (filled($entry['via'] ?? null) ? self::labels($this->language)['via'].' '.$entry['via'] : '');

            $processor->setValue("we_periode#{$i}", self::period((string) $entry['van'], (string) $entry['tot']));
            $processor->setValue("we_functie#{$i}", (string) $entry['functie']);
            $processor->setValue("we_organisatie#{$i}", (string) $entry['organisatie']);
            $processor->setValue("we_subtitel#{$i}", $subtitle);

            $bullets = array_values(array_map('strval', (array) ($entry['bullets'] ?? [])));
            $processor->cloneBlock("we_bullets#{$i}", 0, true, false, self::escape(array_map(fn (string $bullet): array => ["we_bullet#{$i}" => $bullet], $bullets)));
        }
    }

    /**
     * @param  array<int, mixed>  $projects
     */
    private function projects(TemplateProcessor $processor, array $projects): void
    {
        $projects = array_values(array_filter($projects, 'is_array'));

        $processor->cloneBlock('projecten', 0, true, false, self::escape(array_map(function (array $project): array {
            $suffix = array_values(array_filter([$project['periode'] ?? null, $project['subtitel'] ?? null], 'filled'));
            $technologies = array_values((array) ($project['technologieen'] ?? []));

            return [
                'pr_naam' => (string) $project['naam'],
                'pr_suffix' => $suffix === [] ? '' : '  ·  '.implode('  ·  ', $suffix),
                'pr_omschrijving' => (string) ($project['omschrijving'] ?? ''),
                'pr_tech_label' => $technologies === [] ? '' : self::labels($this->language)['technology'],
                'pr_technologie' => implode(' · ', $technologies),
            ];
        }, $projects)));
    }

    /**
     * Sectie "Aansluiting op de opdracht" (alleen bij een getailord CV).
     *
     * @param  array<int, mixed>  $rows
     */
    private function fit(TemplateProcessor $processor, array $rows): void
    {
        $rows = array_values(array_filter($rows, 'is_array'));

        if ($rows === []) {
            $processor->deleteBlock('aansluiting');

            return;
        }

        $processor->cloneBlock('aansluiting', 1);
        $processor->cloneBlock('aa_rijen', 0, true, false, self::escape(array_map(fn (array $row): array => [
            'aa_eis' => (string) ($row['eis'] ?? ''),
            'aa_invulling' => (string) ($row['invulling'] ?? ''),
        ], $rows)));
    }

    /**
     * Aanvullende secties (bijv. "AI-augmented engineering"): kop + bullets in twee kolommen.
     *
     * @param  array<int, mixed>  $sections
     */
    private function extraSections(TemplateProcessor $processor, array $sections): void
    {
        $sections = array_values(array_filter($sections, fn (mixed $section): bool => is_array($section) && ! empty($section['items'])));
        $processor->cloneBlock('extra_secties', count($sections), true, true);

        foreach ($sections as $index => $section) {
            $i = $index + 1;
            $items = array_values(array_map('strval', (array) $section['items']));
            $half = (int) ceil(count($items) / 2);

            $processor->setValue("es_kop#{$i}", mb_strtoupper((string) $section['kop']));
            $processor->cloneBlock("es_links#{$i}", 0, true, false, self::escape(array_map(fn (string $item): array => ["es_li#{$i}" => $item], array_slice($items, 0, $half))));
            $processor->cloneBlock("es_rechts#{$i}", 0, true, false, self::escape(array_map(fn (string $item): array => ["es_ri#{$i}" => $item], array_slice($items, $half))));
        }
    }

    /**
     * Opleidingen in de volgorde van het profiel (= het CV), daarna losse certificaten.
     *
     * @param  array<int, mixed>  $education
     * @param  array<int, mixed>  $certificates
     */
    private function education(TemplateProcessor $processor, array $education, array $certificates): void
    {
        $rows = [];

        foreach ($education as $item) {
            if (is_array($item)) {
                $name = (string) $item['naam'];
                $institution = (string) ($item['instelling'] ?? '');

                if ($institution !== '' && ! str_contains($name, $institution)) {
                    $name .= ', '.$institution;
                }

                $rows[] = ['opl_periode' => (string) ($item['periode'] ?? ''), 'opl_naam' => $name.(($item['status'] ?? '') === 'lopend' && ! str_contains($name, 'lopend') ? ' (lopend)' : '')];
            }
        }

        foreach ($certificates as $item) {
            if (is_array($item)) {
                $rows[] = ['opl_periode' => (string) ($item['jaar'] ?? ''), 'opl_naam' => (string) $item['naam']];
            }
        }

        $rows === [] ? $processor->deleteRow('opl_periode') : $processor->cloneRowAndSetValues('opl_periode', $rows);
    }

    /**
     * @param  array<int, mixed>  $entries
     */
    private function earlierExperience(TemplateProcessor $processor, array $entries): void
    {
        $rows = array_values(array_map(fn (array $entry): array => [
            'ee_periode' => (string) ($entry['periode'] ?? ''),
            'ee_functie' => (string) ($entry['functie'] ?? ''),
            'ee_organisatie' => (string) ($entry['organisatie'] ?? '').(filled($entry['omschrijving'] ?? null) ? ' - '.$entry['omschrijving'] : ''),
        ], array_filter($entries, 'is_array')));

        $rows === [] ? $processor->deleteRow('ee_periode') : $processor->cloneRowAndSetValues('ee_periode', $rows);
    }

    /**
     * @param  array<int, mixed>  $languages
     * @param  array<int, mixed>  $skills
     */
    private function languagesAndSkills(TemplateProcessor $processor, array $languages, array $skills): void
    {
        $processor->cloneBlock('talen_blok', 0, true, false, self::escape(array_values(array_map(fn (array $language): array => [
            'taal_naam' => (string) $language['taal'].': ',
            'taal_niveau' => (string) ($language['niveau'] ?? ''),
        ], array_filter($languages, 'is_array')))));

        $processor->cloneBlock('vaardigheden_blok', 0, true, false, self::escape(array_values(array_map(fn (mixed $skill): array => [
            'vaardigheid' => (string) $skill,
        ], $skills))));
    }

    /**
     * PhpWord escapet waarden in `cloneBlock`-vervangingen niet; zonder dit levert een `&` een
     * kapot Word-bestand op.
     *
     * @param  array<int, array<string, string>>  $replacements
     * @return array<int, array<string, string>>
     */
    private static function escape(array $replacements): array
    {
        return array_map(fn (array $row): array => array_map(fn (string $value): string => htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8'), $row), $replacements);
    }

    /**
     * "2021-03" → "03-2021"; "heden" blijft "heden".
     */
    public static function period(string $from, string $to): string
    {
        $format = fn (string $value): string => preg_match('/^(\d{4})-(\d{2})$/', $value, $m) === 1 ? $m[2].'-'.$m[1] : $value;

        return $format($from).' – '.$format($to);
    }

    /**
     * Vaste teksten in de Nederlandse template die geen placeholder zijn, voor een Engels CV.
     */
    private static function translateFixedTexts(string $docx): void
    {
        $zip = new \ZipArchive;

        if ($zip->open($docx) !== true) {
            return;
        }

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = (string) $zip->getNameIndex($index);

            if (preg_match('#^word/(document|footer\d*|header\d*)\.xml$#', $name) === 1) {
                $xml = (string) $zip->getFromName($name);
                $zip->addFromString($name, str_replace(['AANSLUITING OP DE OPDRACHT', '>Pagina '], ['FIT WITH THE ASSIGNMENT', '>Page '], $xml));
            }
        }

        $zip->close();
    }
}
