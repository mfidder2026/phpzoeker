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
 * Met het master-profiel reproduceert dit het master-CV 1-op-1 (fase 1).
 */
class CvTemplateRenderer
{
    public function __construct(private readonly CvSettings $settings) {}

    public static function templatePath(string $language = 'nl'): string
    {
        return resource_path("templates/cv/fremen-cv-{$language}.docx");
    }

    /**
     * @param  array<string, mixed>  $profile  Bijlage D (+ optioneel `aansluiting_op_opdracht`)
     * @param  array{show_birth_date?: bool, show_city?: bool, show_driving_license?: bool, show_nationality?: bool, footer?: string|null}  $options  privacy-opties per aanbieding (§12.6)
     */
    public function render(Candidate $candidate, array $profile, string $outputPath, array $options = [], string $language = 'nl'): string
    {
        $template = self::templatePath($language);

        if (! is_file($template)) {
            throw new RuntimeException("CV-template ontbreekt: {$template}. Draai `php artisan cv:build-template`.");
        }

        WordSettings::setOutputEscapingEnabled(true);
        $processor = new TemplateProcessor($template);

        $processor->setValue('naam', mb_strtoupper((string) ($profile['naam'] ?? $candidate->name)));
        $processor->setValue('cv_titel', (string) ($profile['cv_titel'] ?? ''));
        $processor->setValue('tagline', (string) ($profile['tagline'] ?? ''));
        $processor->setValue('contactregel', $this->contactLine($candidate, $options));
        $processor->setValue('extra_regel', $this->extraLine($candidate, $options));
        $processor->setValue('profieltekst', (string) ($profile['profieltekst'] ?? ''));
        $processor->setValue('voetregel', (string) ($options['footer'] ?? $this->settings->footer_text));

        $this->photo($processor, $candidate);
        $this->expertise($processor, (array) ($profile['kernexpertise'] ?? []));
        $this->workExperience($processor, (array) ($profile['werkervaring'] ?? []));
        $this->projects($processor, (array) ($profile['projecten'] ?? []));
        $this->fit($processor, (array) ($profile['aansluiting_op_opdracht'] ?? []));
        $this->education($processor, (array) ($profile['opleidingen'] ?? []), (array) ($profile['certificaten'] ?? []));
        $this->earlierExperience($processor, (array) ($profile['eerdere_ervaring'] ?? []));

        $processor->setValue('talen', collect((array) ($profile['talen'] ?? []))
            ->map(fn (array $language): string => $language['taal'].' ('.$language['niveau'].')')
            ->implode(' · '));
        $processor->setValue('vaardigheden', implode(' · ', (array) ($profile['overige_vaardigheden'] ?? [])));

        if (! is_dir(dirname($outputPath))) {
            mkdir(dirname($outputPath), 0775, true);
        }

        $processor->saveAs($outputPath);

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

        if ($this->settings->contact_line === 'candidate') {
            $parts[] = (string) $candidate->phone;
            $parts[] = (string) $candidate->email;
        } else {
            $parts[] = 'Contact via Fremen: '.trim($this->settings->fremen_contact_name.' · '.$this->settings->fremen_contact_email.' '.$this->settings->fremen_contact_phone);
        }

        $showLicense = $options['show_driving_license'] ?? ($this->settings->show_driving_license === 'always');

        if ($showLicense && $candidate->driving_license) {
            $parts[] = 'Rijbewijs B'.($candidate->own_transport ? ' / eigen auto' : '');
        }

        return implode(' | ', array_filter($parts, fn (string $part): bool => trim($part) !== ''));
    }

    /**
     * Geboortedatum alleen als dat expliciet aanstaat (R4); nationaliteit alleen bij screening.
     *
     * @param  array<string, mixed>  $options
     */
    public function extraLine(Candidate $candidate, array $options = []): string
    {
        $parts = [];

        if (($options['show_nationality'] ?? false) && filled($candidate->nationality)) {
            $parts[] = 'Nationaliteit: '.$candidate->nationality;
        }

        if (($options['show_birth_date'] ?? $this->settings->show_birth_date) && filled($candidate->birth_date)) {
            $parts[] = 'Geboortedatum: '.Carbon::parse($candidate->birth_date)->format('d-m-Y');
        }

        return implode(' | ', $parts);
    }

    private function photo(TemplateProcessor $processor, Candidate $candidate): void
    {
        $path = $candidate->photo_path !== null ? storage_path('app/private/'.$candidate->photo_path) : null;

        if ($path !== null && is_file($path)) {
            $processor->setImageValue('foto', ['path' => $path, 'width' => 105, 'height' => 130, 'ratio' => true]);

            return;
        }

        $processor->setValue('foto', '');
    }

    /**
     * Kernexpertise in twee kolommen: blokken om en om links en rechts (cloneRow).
     *
     * @param  array<int, mixed>  $blocks
     */
    private function expertise(TemplateProcessor $processor, array $blocks): void
    {
        $rows = (int) max(1, ceil(count($blocks) / 2));
        $processor->cloneRow('ke_kop_1', $rows);

        for ($row = 1; $row <= $rows; $row++) {
            foreach ([1, 2] as $column) {
                $block = $blocks[($row - 1) * 2 + ($column - 1)] ?? null;
                $items = is_array($block) ? collect((array) ($block['items'] ?? []))->map(fn (array $item): string => $item['naam']
                    .(! empty($item['niveau']) ? ' ('.$item['niveau'].')' : '')
                    .(! empty($item['sinds']) ? ' sinds '.$item['sinds'] : ''))->implode(' · ') : '';

                $processor->setValue("ke_kop_{$column}#{$row}", is_array($block) ? (string) ($block['kop'] ?? '') : '');
                $processor->setValue("ke_items_{$column}#{$row}", $items);
            }
        }
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
            $processor->setValue("we_periode#{$i}", self::period((string) $entry['van'], (string) $entry['tot']));
            $processor->setValue("we_functie#{$i}", (string) $entry['functie']);
            $processor->setValue("we_organisatie#{$i}", (string) $entry['organisatie'].(! empty($entry['via']) ? ' (via '.$entry['via'].')' : ''));
            $processor->setValue("we_subtitel#{$i}", (string) ($entry['subtitel'] ?? ''));
            $processor->setValue("we_technologie#{$i}", ! empty($entry['technologieen']) ? 'Technologie: '.implode(', ', (array) $entry['technologieen']) : '');

            $bullets = array_values((array) ($entry['bullets'] ?? []));
            $processor->cloneBlock("we_bullets#{$i}", count($bullets), true, true);

            foreach ($bullets as $bulletIndex => $bullet) {
                $processor->setValue("we_bullet#{$i}#".($bulletIndex + 1), (string) $bullet);
            }
        }
    }

    /**
     * @param  array<int, mixed>  $projects
     */
    private function projects(TemplateProcessor $processor, array $projects): void
    {
        $projects = array_values(array_filter($projects, 'is_array'));

        $processor->cloneBlock('projecten', 0, true, false, array_map(fn (array $project): array => [
            'pr_naam' => (string) $project['naam'].(! empty($project['periode']) ? ' · '.$project['periode'] : ''),
            'pr_omschrijving' => (string) ($project['omschrijving'] ?? ''),
            'pr_technologie' => ! empty($project['technologieen']) ? 'Technologie: '.implode(', ', (array) $project['technologieen']) : '',
        ], $projects));
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

        $processor->cloneBlock('aansluiting', 0, true, false, array_map(fn (array $row): array => [
            'aa_eis' => (string) ($row['eis'] ?? ''),
            'aa_invulling' => (string) ($row['invulling'] ?? ''),
        ], $rows));
    }

    /**
     * @param  array<int, mixed>  $education
     * @param  array<int, mixed>  $certificates
     */
    private function education(TemplateProcessor $processor, array $education, array $certificates): void
    {
        $rows = [];

        foreach ($education as $item) {
            if (is_array($item)) {
                $rows[] = [
                    'opl_periode' => (string) ($item['periode'] ?? ''),
                    'opl_naam' => (string) $item['naam'].(! empty($item['instelling']) ? ', '.$item['instelling'] : '').(($item['status'] ?? '') === 'lopend' ? ' (lopend)' : ''),
                ];
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
            'ee_organisatie' => (string) ($entry['organisatie'] ?? ''),
        ], array_filter($entries, 'is_array')));

        $rows === [] ? $processor->deleteRow('ee_periode') : $processor->cloneRowAndSetValues('ee_periode', $rows);
    }

    /**
     * "2021-03" → "03-2021"; "heden" blijft "heden".
     */
    public static function period(string $from, string $to): string
    {
        $format = fn (string $value): string => preg_match('/^(\d{4})-(\d{2})$/', $value, $m) === 1 ? $m[2].'-'.$m[1] : $value;

        return $format($from).' – '.$format($to);
    }
}
