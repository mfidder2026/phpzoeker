<?php

namespace App\Console\Commands;

use App\Ai\Agents\AssignmentExtractor;
use App\Ai\Runs\AiTask;
use App\Ai\Runs\RunTracker;
use App\Ai\Support\PromptRepository;
use App\Domain\Assignments\Support\AssignmentNormalizer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/**
 * Evaluatie van de extractie (§18.4, acceptatie fase 2): per fixture de velden titel, plaats,
 * uren, tarief en deadline (plus contractvorm en relevantie) vergelijken met de verwachting.
 * Draait tegen het echte model en kost tokens; niet in elke CI-run.
 */
#[Signature('eval:extraction {--path= : Map met *.txt en *.expected.json} {--field-threshold=0.9 : Minimale score}')]
#[Description('Meet de kwaliteit van de opdrachtextractie op de evaluatieset')]
class EvalExtraction extends Command
{
    /**
     * @var list<string>
     */
    private const FIELDS = ['title', 'city', 'hours_min', 'hours_max', 'max_rate', 'deadline', 'contract_type', 'is_relevant'];

    public function handle(AssignmentExtractor $extractor, AssignmentNormalizer $normalizer, PromptRepository $prompts): int
    {
        $path = $this->option('path');
        $path = is_string($path) && $path !== '' ? $path : base_path('tests/Fixtures/postings');
        $files = glob(rtrim($path, '/\\').'/*.txt') ?: [];
        $scores = array_fill_keys(self::FIELDS, ['ok' => 0, 'total' => 0]);
        $rows = [];

        foreach ($files as $file) {
            $expectedFile = preg_replace('/\.txt$/', '.expected.json', $file);
            $expected = is_string($expectedFile) && is_file($expectedFile) ? json_decode((string) file_get_contents($expectedFile), true) : null;

            if (! is_array($expected)) {
                continue;
            }

            $text = (string) file_get_contents($file);
            $title = trim((string) strtok($text, "\n"));
            $run = RunTracker::start(AiTask::Extract, AssignmentExtractor::class, steps: [['key' => 'extract', 'label' => 'Extractie (evaluatie)', 'weight' => 100]],
                subjectLabel: 'Evaluatie extractie — '.basename($file), promptVersion: $prompts->label(AssignmentExtractor::PROMPT));

            try {
                $run->step('extract')->begin();
                $attributes = $normalizer->normalize($extractor->extract($run, $title, $text), $text);
                $run->step('extract')->complete();
                $run->finish();
            } catch (Throwable $exception) {
                $run->fail($exception);
                $this->components->error(basename($file).': '.$exception->getMessage());

                continue;
            }

            $actual = [
                'title' => $attributes['title'],
                'city' => $attributes['city'],
                'hours_min' => $attributes['hours_min'],
                'hours_max' => $attributes['hours_max'],
                'max_rate' => $attributes['max_rate'] === null ? null : (float) $attributes['max_rate'],
                'deadline' => $attributes['deadline_at']?->timezone(AssignmentNormalizer::TIMEZONE)->format('Y-m-d H:i'),
                'contract_type' => $attributes['contract_type'] === 'onbekend' ? null : $attributes['contract_type'],
                'is_relevant' => $attributes['is_relevant'],
            ];

            $line = [basename($file, '.txt')];

            foreach (self::FIELDS as $field) {
                $ok = self::matches($field, $expected[$field] ?? null, $actual[$field]);
                $scores[$field]['total']++;
                $scores[$field]['ok'] += $ok ? 1 : 0;
                $line[] = $ok ? '✓' : '✕ '.json_encode($actual[$field], JSON_UNESCAPED_UNICODE);
            }

            $rows[] = $line;
        }

        $this->table(['Fixture', ...self::FIELDS], $rows);

        $core = ['title', 'city', 'hours_min', 'max_rate', 'deadline'];
        $ok = array_sum(array_map(fn (string $field): int => $scores[$field]['ok'], $core));
        $total = array_sum(array_map(fn (string $field): int => $scores[$field]['total'], $core));
        $score = $total === 0 ? 0 : $ok / $total;

        $this->components->info(sprintf('Kernvelden (titel, plaats, uren, tarief, deadline): %d/%d = %.0f%% · prompt %s', $ok, $total, $score * 100, $prompts->label(AssignmentExtractor::PROMPT)));

        return $score >= (float) $this->option('field-threshold') ? self::SUCCESS : self::FAILURE;
    }

    private static function matches(string $field, mixed $expected, mixed $actual): bool
    {
        if ($field === 'title') {
            return is_string($actual) && $actual !== ''
                && (mb_stripos($actual, (string) $expected) !== false || mb_stripos((string) $expected, $actual) !== false);
        }

        if ($field === 'city') {
            return mb_strtolower((string) $expected) === mb_strtolower((string) $actual);
        }

        if (in_array($field, ['hours_min', 'hours_max', 'max_rate'], true)) {
            return $expected === null ? $actual === null : $actual !== null && abs((float) $expected - (float) $actual) < 0.01;
        }

        return $expected === $actual;
    }
}
