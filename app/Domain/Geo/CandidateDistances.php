<?php

namespace App\Domain\Geo;

use App\Domain\Assignments\Models\Assignment;
use App\Domain\Candidates\Enums\CandidateStatus;
use App\Domain\Candidates\Models\Candidate;

/**
 * Reisafstand van elke actieve kandidaat naar de opdracht (FR-O2). Woonplaatsen worden via de
 * geocoder opgezocht en bij de kandidaat bewaard.
 */
class CandidateDistances
{
    public function __construct(private readonly Geocoder $geocoder) {}

    /**
     * @return list<array{candidate: Candidate, km: float|null, minutes: int|null}>
     */
    public function for(Assignment $assignment): array
    {
        $candidates = Candidate::query()
            ->whereIn('status', [CandidateStatus::Beschikbaar->value, CandidateStatus::InProcedure->value])
            ->orderBy('name')
            ->get();

        $rows = [];

        foreach ($candidates as $candidate) {
            $this->ensureLocation($candidate);

            $km = $assignment->lat !== null && $assignment->lng !== null && $candidate->getAttribute('lat') !== null
                ? Distance::roadKm((float) $candidate->getAttribute('lat'), (float) $candidate->getAttribute('lng'), (float) $assignment->lat, (float) $assignment->lng)
                : null;

            $rows[] = ['candidate' => $candidate, 'km' => $km, 'minutes' => $km !== null ? Distance::travelMinutes($km) : null];
        }

        return $rows;
    }

    /**
     * Reistijd in minuten van één kandidaat naar de opdracht, of null als een locatie onbekend is.
     */
    public function minutesFor(Assignment $assignment, Candidate $candidate): ?int
    {
        if ($assignment->lat === null || $assignment->lng === null) {
            return null;
        }

        try {
            $this->ensureLocation($candidate);
        } catch (\Throwable) {
            return null;
        }

        if ($candidate->getAttribute('lat') === null) {
            return null;
        }

        return Distance::travelMinutes(Distance::roadKm((float) $candidate->getAttribute('lat'), (float) $candidate->getAttribute('lng'), (float) $assignment->lat, (float) $assignment->lng));
    }

    public function ensureLocation(Candidate $candidate): void
    {
        if ($candidate->getAttribute('lat') !== null || blank($candidate->city)) {
            return;
        }

        $geo = $this->geocoder->geocode((string) $candidate->city);

        if ($geo !== null) {
            $candidate->forceFill(['lat' => $geo['lat'], 'lng' => $geo['lng']])->saveQuietly();
        }
    }
}
