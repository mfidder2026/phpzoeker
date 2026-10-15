<?php

namespace App\Domain\Matching\Actions;

use App\Ai\Agents\MatchAssessor;
use App\Ai\Budget\AiBudgetGuard;
use App\Ai\Exceptions\AiProviderException;
use App\Ai\Runs\AiTask;
use App\Ai\Runs\RunTracker;
use App\Ai\Support\PromptRepository;
use App\Domain\Assignments\Enums\AssignmentStatus;
use App\Domain\Assignments\Models\Assignment;
use App\Domain\Candidates\Models\Candidate;
use App\Domain\Geo\CandidateDistances;
use App\Domain\Matching\Enums\AssessmentStatus;
use App\Domain\Matching\Enums\MatchLabel;
use App\Domain\Matching\Enums\MatchStatus;
use App\Domain\Matching\Enums\Recommendation;
use App\Domain\Matching\Models\CandidateMatch;
use App\Domain\Matching\Notifications\MatchNotifier;
use App\Domain\Matching\Support\AssessmentGuard;
use App\Domain\Matching\Support\HardFilters;
use App\Domain\Matching\Support\Prefilter;
use App\Domain\Matching\Support\ProfileFacts;
use App\Domain\Matching\Support\ScoreCalculator;
use App\Domain\Matching\Support\Technologies;
use App\Models\User;
use App\Settings\MatchingSettings;
use Illuminate\Support\Str;

/**
 * Berekent de match van één opdracht met één kandidaat (§10): prefilter → harde filters →
 * AI-beoordeling (alleen als nodig en niet al eerder gedaan voor dezelfde invoer) → score →
 * label. Een nieuwe sterke match leidt tot een directe melding (FR-N2).
 */
class MatchCandidate
{
    /**
     * @var list<array{key: string, label: string, weight: int, optional?: bool}>
     */
    public const STEPS = [
        ['key' => 'assess', 'label' => 'Eisen en wensen beoordelen', 'weight' => 90],
        ['key' => 'score', 'label' => 'Score en label berekenen', 'weight' => 10],
    ];

    public function __construct(
        private readonly MatchingSettings $settings,
        private readonly MatchAssessor $assessor,
        private readonly AiBudgetGuard $budget,
        private readonly CandidateDistances $distances,
        private readonly PromptRepository $prompts,
        private readonly MatchNotifier $notifier,
    ) {}

    /**
     * @param  bool  $assess  false = alleen opnieuw rekenen met een bestaande beoordeling (bijv. na een gewichtswijziging)
     * @param  bool  $force  true = altijd opnieuw beoordelen, ook bij dezelfde invoer (knop "Opnieuw beoordelen")
     * @param  bool  $notify  false = geen meldingen en geen statuswijziging van de opdracht (evaluatieset)
     */
    public function handle(Assignment $assignment, Candidate $candidate, bool $assess = true, bool $force = false, ?User $startedBy = null, bool $notify = true): CandidateMatch
    {
        $candidate->loadMissing(['activeProfile', 'searchProfile', 'absences']);
        $technologies = Technologies::fromSettings();
        $facts = ProfileFacts::for($candidate, $technologies);

        /** @var CandidateMatch $match */
        $match = CandidateMatch::query()->firstOrNew(['assignment_id' => $assignment->id, 'candidate_id' => $candidate->id]);
        $match->setRelation('assignment', $assignment);
        $match->setRelation('candidate', $candidate);
        $wasStrong = $match->exists && $match->label === MatchLabel::Sterk;

        $travelMinutes = $this->distances->minutesFor($assignment, $candidate);
        $prefilter = (new Prefilter($technologies))->evaluate($assignment, $candidate->searchProfile, $facts);
        $filters = (new HardFilters($this->settings))->evaluate($assignment, $candidate, $travelMinutes);
        $hardPassed = HardFilters::passed($filters);
        $needsAssessment = $facts !== null && $prefilter['passed'] && $hardPassed;
        $inputHash = $facts !== null ? $this->inputHash($assignment, $facts) : null;
        $guard = new AssessmentGuard($technologies);

        $hasCurrentAssessment = $match->assessment !== null && $match->input_hash === $inputHash;
        $assessment = $hasCurrentAssessment ? $match->assessment : null;
        $assessmentStatus = $hasCurrentAssessment ? AssessmentStatus::Klaar : ($needsAssessment ? AssessmentStatus::Wacht : AssessmentStatus::NietNodig);
        $run = null;

        if ($needsAssessment && ($force || ! $hasCurrentAssessment) && ($assess || $force)) {
            if (! $match->exists) {
                $match->forceFill(['status' => MatchStatus::Nieuw, 'status_changed_at' => now()])->save();
            }

            if ($startedBy === null && ! $this->budget->allowsMatching()) {
                $assessmentStatus = AssessmentStatus::Budget;
            } else {
                $run = RunTracker::start(
                    task: AiTask::Match,
                    agent: MatchAssessor::class,
                    subject: $assignment,
                    startedBy: $startedBy,
                    steps: self::STEPS,
                    subjectLabel: 'Match beoordelen — '.Str::limit($assignment->title, 60).' × '.$candidate->name,
                    promptVersion: $this->prompts->label(MatchAssessor::PROMPT),
                );

                try {
                    $step = $run->step('assess')->begin();
                    $raw = $this->assessor->assess($run, $assignment, $facts, $guard->yearsTable($assignment, $facts), userInitiated: $startedBy !== null);
                    $step->complete();

                    $assessment = $guard->apply($raw, $assignment, $facts)['assessment'];
                    $assessmentStatus = AssessmentStatus::Klaar;
                    $run->step('score')->begin();
                } catch (AiProviderException $exception) {
                    $run->fail($exception);
                    $run = null;
                    $assessmentStatus = $exception->errorCode === 'budget_exceeded' ? AssessmentStatus::Budget : AssessmentStatus::Mislukt;
                }
            }
        }

        // Een bestaande beoordeling opnieuw door de guard: regels of profieljaren kunnen veranderd zijn.
        if ($assessment !== null && $facts !== null && $assessmentStatus === AssessmentStatus::Klaar && $run === null) {
            $assessment = $guard->apply($assessment, $assignment, $facts)['assessment'];
        }

        $knockoutFailed = ($assessment['knock_out_gefaald'] ?? []) !== [];
        $partialAbsence = collect($filters)->contains(fn (array $filter): bool => $filter['key'] === 'afwezigheid' && $filter['outcome'] === HardFilters::WARNING);

        $score = (new ScoreCalculator($this->settings, $technologies))->calculate(
            $assignment,
            $candidate,
            $assessment,
            $facts?->technologies() ?? [],
            $travelMinutes,
            $partialAbsence,
        );

        $recommendation = $assessment !== null ? Recommendation::tryFrom((string) $assessment['aanbeveling']) : null;
        $overridden = $match->knockout_override_reason !== null;

        $label = $this->label($score['score'], $prefilter['passed'], $hardPassed, $needsAssessment && $assessment === null, $knockoutFailed && ! $overridden, $recommendation, $overridden);

        $match->forceFill([
            'prefilter_passed' => $prefilter['passed'],
            'prefilter_score' => $prefilter['score'],
            'prefilter_reasons' => $prefilter['reasons'],
            'hard_filter_passed' => $hardPassed,
            'filter_results' => $filters,
            'score' => $score['score'],
            'score_breakdown' => $score['breakdown'],
            'assessment' => $assessment,
            'assessment_status' => $assessmentStatus,
            'input_hash' => $assessment !== null ? $inputHash : $match->input_hash,
            'label' => $label,
            'recommendation' => $recommendation,
            'warnings' => $this->warnings($score['warnings'], $filters, $assessment, $knockoutFailed, $overridden, $assessmentStatus),
            'knockout_failed' => $knockoutFailed,
            'profile_version' => $facts?->profileVersion(),
            'computed_at' => now(),
        ]);

        if ($run !== null) {
            $match->forceFill([
                'model' => $run->run->model ?? $run->profile?->modelFor(AiTask::Match),
                'prompt_version' => $this->prompts->label(MatchAssessor::PROMPT),
                'ai_run_id' => $run->id(),
            ]);
        }

        if (! $match->exists) {
            $match->forceFill(['status' => MatchStatus::Nieuw, 'status_changed_at' => now()]);
        }

        $match->save();

        if ($run !== null) {
            $run->step('score')->complete();
            $run->finish(['match_id' => $match->id, 'score' => $match->score, 'label' => $label->value]);
        }

        if (! $notify) {
            return $match;
        }

        if ($label->isPromising() && $assignment->status === AssignmentStatus::Nieuw) {
            $assignment->forceFill(['status' => AssignmentStatus::Gematcht])->save();
        }

        if ($label === MatchLabel::Sterk && ! $wasStrong && $match->notified_at === null) {
            $this->notifier->strongMatch($match);
        }

        return $match;
    }

    private function label(int $score, bool $prefilterPassed, bool $hardPassed, bool $awaitingAssessment, bool $knockoutBlocking, ?Recommendation $recommendation, bool $overridden): MatchLabel
    {
        if (! $hardPassed) {
            return MatchLabel::Afgevallen;
        }

        if (! $prefilterPassed || $awaitingAssessment) {
            return MatchLabel::Zwak;
        }

        // Een gefaalde knock-out (niet overruled) komt niet in de digest; zichtbaar via het filter Label.
        if ($knockoutBlocking) {
            return MatchLabel::Zwak;
        }

        $blocked = $recommendation === Recommendation::NietAanbieden && ! $overridden;

        if ($score >= $this->settings->strong_threshold && ! $blocked) {
            return MatchLabel::Sterk;
        }

        if ($score >= $this->settings->possible_threshold) {
            return MatchLabel::Mogelijk;
        }

        return MatchLabel::Zwak;
    }

    /**
     * @param  list<string>  $scoreWarnings
     * @param  list<array{key: string, label: string, outcome: string, detail: string}>  $filters
     * @param  array<string, mixed>|null  $assessment
     * @return list<string>
     */
    private function warnings(array $scoreWarnings, array $filters, ?array $assessment, bool $knockoutFailed, bool $overridden, AssessmentStatus $status): array
    {
        $warnings = $scoreWarnings;

        foreach ($filters as $filter) {
            if ($filter['outcome'] === HardFilters::WARNING) {
                $warnings[] = $filter['label'].': '.$filter['detail'];
            }
        }

        if ($knockoutFailed) {
            $warnings[] = 'Knock-out-eis niet gehaald: '.implode(', ', (array) ($assessment['knock_out_gefaald'] ?? [])).($overridden ? ' (overruled)' : '');
        }

        if ($status === AssessmentStatus::Budget) {
            $warnings[] = 'Nog niet beoordeeld: het AI-budget voor matching is voor vandaag op.';
        } elseif ($status === AssessmentStatus::Mislukt) {
            $warnings[] = 'De AI-beoordeling is mislukt; zie AI-activiteit.';
        }

        return array_values(array_unique($warnings));
    }

    /**
     * Dezelfde opdrachtinhoud en hetzelfde profiel → geen nieuwe AI-aanroep (§18.3). Een nieuwe
     * promptversie telt bewust niet mee: opnieuw beoordelen gaat dan op verzoek (§10.6).
     */
    private function inputHash(Assignment $assignment, ProfileFacts $facts): string
    {
        return hash('sha256', (string) json_encode(self::canonical([
            $assignment->title,
            $assignment->summary,
            $assignment->seniority,
            $assignment->requirements,
            $assignment->wishes,
            $assignment->technologies,
            $facts->profileVersion(),
            $facts->candidate->seniority->value,
        ])));
    }

    /**
     * Sleutels gesorteerd: PostgreSQL (jsonb) bewaart objecten in een andere volgorde dan ze zijn
     * opgeslagen, en dezelfde inhoud moet dezelfde hash geven.
     */
    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::canonical(...), $value);
    }
}
