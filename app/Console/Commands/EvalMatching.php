<?php

namespace App\Console\Commands;

use App\Domain\Assignments\Enums\AssignmentStatus;
use App\Domain\Assignments\Models\Assignment;
use App\Domain\Candidates\Models\Candidate;
use App\Domain\Matching\Actions\MatchCandidate;
use App\Domain\Matching\Enums\MatchLabel;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

/**
 * Evaluatie van de matching (§18.4, acceptatie fase 3): per opdracht uit de evaluatieset de
 * verwachte uitkomst per kandidaat (aanbieden / twijfel / niet) naast het label van de app.
 * Rapporteert precisie en recall per label; "sterke match" moet een precisie van ≥ 70% halen.
 *
 * Elk bestand is JSON: {"opdracht": {velden van een opdracht}, "verwacht": {"<slug of alias>": "aanbieden|twijfel|niet"}}.
 * De echte, door Fremen gelabelde set staat buiten git in storage/app/private/eval/matching.
 * Draait tegen het echte model en kost tokens; niet in elke CI-run.
 */
#[Signature('eval:matching {--path= : Map met *.json-bestanden} {--map=* : Alias naar kandidaat-slug, bijv. senior=mark-van-der-zande} {--min-precision=0.7 : Minimale precisie voor "sterke match"}')]
#[Description('Meet precisie en recall van de matchlabels op de evaluatieset')]
class EvalMatching extends Command
{
    /**
     * @var array<string, string>
     */
    private const LABEL_TO_EXPECTATION = [
        MatchLabel::Sterk->value => 'aanbieden',
        MatchLabel::Mogelijk->value => 'twijfel',
        MatchLabel::Zwak->value => 'niet',
        MatchLabel::Afgevallen->value => 'niet',
    ];

    public function handle(MatchCandidate $matcher): int
    {
        $path = $this->option('path');
        $path = is_string($path) && $path !== '' ? $path : storage_path('app/private/eval/matching');

        if (! is_dir($path)) {
            $path = base_path('tests/Fixtures/matching');
        }

        $aliases = $this->aliases();
        $files = glob(rtrim($path, '/\\').'/*.json') ?: [];
        $pairs = [];
        $rows = [];

        foreach ($files as $file) {
            $case = json_decode((string) file_get_contents($file), true);

            if (! is_array($case) || ! is_array($case['opdracht'] ?? null) || ! is_array($case['verwacht'] ?? null)) {
                $this->components->warn(basename($file).': ongeldig formaat, overgeslagen.');

                continue;
            }

            $assignment = Assignment::query()->create([
                'title' => 'Evaluatie',
                ...$case['opdracht'],
                'dedupe_group_id' => (string) Str::uuid(),
                'status' => AssignmentStatus::Genegeerd,
                'ignore_reason' => 'Evaluatieset (tijdelijk)',
                'is_relevant' => true,
            ]);

            try {
                foreach ($case['verwacht'] as $key => $expected) {
                    $slug = $aliases[$key] ?? (string) $key;
                    $candidate = Candidate::query()->where('slug', $slug)->first();

                    if ($candidate === null) {
                        $this->components->warn(basename($file).": kandidaat '{$slug}' bestaat niet.");

                        continue;
                    }

                    try {
                        $match = $matcher->handle($assignment, $candidate, force: true, notify: false);
                    } catch (Throwable $exception) {
                        $this->components->error(basename($file).': '.$exception->getMessage());

                        continue;
                    }

                    $predicted = self::LABEL_TO_EXPECTATION[$match->label->value];
                    $pairs[] = ['expected' => (string) $expected, 'predicted' => $predicted];
                    $rows[] = [
                        basename($file, '.json'),
                        $candidate->name,
                        $expected,
                        $match->label->getLabel().' ('.$match->score.')',
                        $predicted === $expected ? '✓' : '✕',
                    ];
                }
            } finally {
                $assignment->delete();
            }
        }

        if ($pairs === []) {
            $this->components->error("Geen evaluatiegevallen gevonden in {$path}.");

            return self::FAILURE;
        }

        $this->table(['Opdracht', 'Kandidaat', 'Verwacht', 'App', ''], $rows);

        $summary = [];

        foreach (['aanbieden' => 'Sterke match', 'twijfel' => 'Mogelijke match', 'niet' => 'Zwak/afgevallen'] as $value => $label) {
            $predictedCount = count(array_filter($pairs, fn (array $pair): bool => $pair['predicted'] === $value));
            $expectedCount = count(array_filter($pairs, fn (array $pair): bool => $pair['expected'] === $value));
            $hits = count(array_filter($pairs, fn (array $pair): bool => $pair['predicted'] === $value && $pair['expected'] === $value));

            $summary[$value] = $predictedCount === 0 ? null : $hits / $predictedCount;
            $this->line(sprintf('  %-16s precisie %s · recall %s', $label,
                $predictedCount === 0 ? '—' : round($hits / $predictedCount * 100).'%',
                $expectedCount === 0 ? '—' : round($hits / $expectedCount * 100).'%'));
        }

        $minimum = (float) $this->option('min-precision');
        $precision = $summary['aanbieden'];

        if ($precision !== null && $precision < $minimum) {
            $this->components->error('Precisie "sterke match" '.round($precision * 100).'% is lager dan '.round($minimum * 100).'%.');

            return self::FAILURE;
        }

        $this->components->info('Precisie "sterke match": '.($precision === null ? 'geen sterke matches voorspeld' : round($precision * 100).'%').'.');

        return self::SUCCESS;
    }

    /**
     * @return array<string, string>
     */
    private function aliases(): array
    {
        $aliases = [];

        foreach ((array) $this->option('map') as $mapping) {
            if (is_string($mapping) && str_contains($mapping, '=')) {
                [$alias, $slug] = explode('=', $mapping, 2);
                $aliases[trim($alias)] = trim($slug);
            }
        }

        return $aliases;
    }
}
