<?php

namespace App\Domain\Candidates\Actions;

use App\Domain\Candidates\Enums\CandidateStatus;
use App\Domain\Candidates\Enums\Seniority;
use App\Domain\Candidates\Models\Candidate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Nieuwe kandidaat vanuit een geüpload CV: eerst een tijdelijke kandidaat (inactief), daarna vult
 * de CV-import alle gegevens in die in het CV staan. Wat er niet in staat (zoals het tarief), vult
 * een mens daarna aan.
 */
class CandidateFromCv
{
    public const PLACEHOLDER_NAME = 'Nieuwe kandidaat (CV wordt gelezen)';

    /**
     * Tijdelijke kandidaat met de verplichte velden; inactief tot het CV is gelezen.
     */
    public function placeholder(): Candidate
    {
        return Candidate::query()->create([
            'name' => self::PLACEHOLDER_NAME,
            'slug' => 'nieuwe-kandidaat-'.Str::lower(Str::random(6)),
            'job_title' => 'Onbekend',
            'seniority' => Seniority::Medior,
            'status' => CandidateStatus::Inactief,
            'hours_per_week' => 40,
            'hourly_rate' => 0,
            'min_hourly_rate' => 0,
        ]);
    }

    public function isPlaceholder(Candidate $candidate): bool
    {
        return $candidate->name === self::PLACEHOLDER_NAME;
    }

    /**
     * Vult de kandidaat met de gegevens uit het CV. Naam, slug, functietitel en senioriteit worden
     * overschreven (ze komen van de tijdelijke kandidaat); andere velden alleen als ze nog leeg zijn.
     *
     * @param  array<string, mixed>  $profile  master-profiel uit de CV-import
     * @param  array<string, mixed>  $details  uitkomst van `CandidateDetailsExtractor`
     * @return list<string> welke gegevens zijn ingevuld (zonder de waarden)
     */
    public function fill(Candidate $candidate, array $profile, array $details): array
    {
        $filled = [];
        $name = $details['naam'] ?? (is_string($profile['naam'] ?? null) && trim($profile['naam']) !== '' ? trim($profile['naam']) : null);
        $title = $details['functietitel'] ?? (is_string($profile['cv_titel'] ?? null) && trim($profile['cv_titel']) !== '' ? trim($profile['cv_titel']) : null);

        if ($name !== null) {
            $placeholderSlug = $candidate->slug;
            $candidate->name = $name;
            $candidate->slug = $this->uniqueSlug($name, $candidate);
            $this->renamePhoto($candidate, $placeholderSlug);
            $filled[] = 'naam';
        }

        if ($title !== null) {
            $candidate->job_title = $title;
            $candidate->cv_titles = array_values(array_unique(array_filter([$title, is_string($profile['cv_titel'] ?? null) ? trim($profile['cv_titel']) : null])));
            $filled[] = 'functietitel';
        }

        if (($seniority = Seniority::tryFrom((string) ($details['senioriteit'] ?? ''))) !== null) {
            $candidate->seniority = $seniority;
            $filled[] = 'senioriteit';
        }

        $whenBlank = [
            'city' => ['woonplaats', 'woonplaats'],
            'postcode' => ['postcode', 'postcode'],
            'nationality' => ['nationaliteit', 'nationaliteit'],
            'own_transport' => ['eigen_vervoer', 'eigen vervoer'],
            'available_from' => ['beschikbaar_vanaf', 'beschikbaar vanaf'],
        ];

        foreach ($whenBlank as $attribute => [$key, $label]) {
            if ($candidate->{$attribute} === null && ($details[$key] ?? null) !== null) {
                $candidate->{$attribute} = $details[$key];
                $filled[] = $label;
            }
        }

        if (($details['rijbewijs'] ?? null) === true && ! $candidate->driving_license) {
            $candidate->driving_license = true;
            $filled[] = 'rijbewijs';
        }

        $languages = [];

        foreach ((array) ($details['talen'] ?? []) as $language) {
            if (is_array($language) && is_string($language['code'] ?? null)) {
                $languages[$language['code']] = is_string($language['niveau'] ?? null) ? $language['niveau'] : '';
            }
        }

        if (blank($candidate->languages) && $languages !== []) {
            $candidate->languages = $languages;
            $filled[] = 'talen';
        }

        if (($details['uren_per_week'] ?? null) !== null) {
            $candidate->hours_per_week = $details['uren_per_week'];
            $filled[] = 'uren per week';
        }

        if ((float) $candidate->hourly_rate === 0.0 && ($details['uurtarief'] ?? null) !== null) {
            $candidate->hourly_rate = $details['uurtarief'];
            $candidate->min_hourly_rate = $details['uurtarief'];
            $filled[] = 'tarief';
        }

        $candidate->status = CandidateStatus::Beschikbaar;
        $candidate->save();

        $search = $candidate->searchProfile()->firstOrNew();

        if (blank($search->roles) && ($details['rollen'] ?? []) !== []) {
            $search->roles = $details['rollen'];
            $filled[] = 'rollen';
        }

        if (blank($search->preferred_tech) && ($details['technologieen'] ?? []) !== []) {
            $search->preferred_tech = $details['technologieen'];
            $filled[] = 'technologieën';
        }

        if ($search->isDirty()) {
            $search->save();
        }

        return $filled;
    }

    /**
     * De pasfoto is bij de CV-import opgeslagen onder de tijdelijke slug; hernoem hem naar de echte.
     */
    private function renamePhoto(Candidate $candidate, string $placeholderSlug): void
    {
        $path = (string) $candidate->photo_path;

        if (! str_starts_with(basename($path), $placeholderSlug.'.') || ! Storage::disk('local')->exists($path)) {
            return;
        }

        $target = dirname($path).'/'.$candidate->slug.'.'.pathinfo($path, PATHINFO_EXTENSION);

        if (Storage::disk('local')->exists($target)) {
            return;
        }

        Storage::disk('local')->move($path, $target);
        $candidate->photo_path = $target;
    }

    private function uniqueSlug(string $name, Candidate $candidate): string
    {
        $base = Str::slug($name) ?: 'kandidaat';
        $slug = $base;
        $suffix = 2;

        while (Candidate::withTrashed()->where('slug', $slug)->whereKeyNot($candidate->getKey())->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }
}
