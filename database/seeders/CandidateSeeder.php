<?php

namespace Database\Seeders;

use App\Domain\Candidates\Models\Candidate;
use DateTimeInterface;
use Illuminate\Database\Seeder;
use Symfony\Component\Yaml\Yaml;

/**
 * Leest config/seed/candidates.yaml (niet in git) of valt terug op het geanonimiseerde
 * voorbeeldbestand (Bijlage A).
 */
class CandidateSeeder extends Seeder
{
    public function run(?string $path = null): void
    {
        $path ??= is_file(config_path('seed/candidates.yaml'))
            ? config_path('seed/candidates.yaml')
            : config_path('seed/candidates.example.yaml');

        /** @var array{candidates?: list<array<string, mixed>>} $data */
        $data = Yaml::parseFile($path, Yaml::PARSE_DATETIME);

        foreach ($data['candidates'] ?? [] as $row) {
            $row = $this->normalizeDates($row);

            /** @var array<string, mixed> $searchProfile */
            $searchProfile = $row['search_profile'] ?? [];
            /** @var list<array<string, mixed>> $absences */
            $absences = $row['absences'] ?? [];

            unset($row['search_profile'], $row['absences'], $row['master_cv']);

            $candidate = Candidate::query()->updateOrCreate(['slug' => $row['slug']], $row);

            $candidate->searchProfile()->updateOrCreate(['candidate_id' => $candidate->id], $searchProfile);

            foreach ($absences as $absence) {
                $candidate->absences()->firstOrCreate([
                    'starts_on' => $absence['starts_on'],
                    'ends_on' => $absence['ends_on'],
                ], $absence);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function normalizeDates(array $row): array
    {
        array_walk_recursive($row, function (mixed &$value): void {
            if ($value instanceof DateTimeInterface) {
                $value = $value->format('Y-m-d');
            }
        });

        return $row;
    }
}
