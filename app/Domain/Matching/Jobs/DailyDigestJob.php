<?php

namespace App\Domain\Matching\Jobs;

use App\Domain\Matching\Enums\AssessmentStatus;
use App\Domain\Matching\Enums\MatchLabel;
use App\Domain\Matching\Mail\DailyDigestMail;
use App\Domain\Matching\Models\CandidateMatch;
use App\Settings\NotificationSettings;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use Spatie\Holidays\Holidays;

/**
 * Dagelijkse digest op werkdagen (FR-N1). Feestdagen in Nederland tellen niet als werkdag;
 * matches van die dagen komen in de eerstvolgende digest.
 */
class DailyDigestJob implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->onQueue('mail');
    }

    public static function isWorkday(CarbonInterface $date): bool
    {
        if ($date->isWeekend()) {
            return false;
        }

        try {
            return ! Holidays::for('nl')->isHoliday($date);
        } catch (\Throwable) {
            return true;
        }
    }

    public function handle(NotificationSettings $settings): void
    {
        $today = now();

        if (! self::isWorkday($today)) {
            return;
        }

        /** @var Collection<int, CandidateMatch> $matches */
        $matches = CandidateMatch::query()
            ->with(['assignment', 'candidate'])
            ->promising()
            ->forOpenAssignments()
            ->where('assessment_status', AssessmentStatus::Klaar->value)
            ->whereNull('digested_at')
            ->get()
            ->sortBy([
                fn (CandidateMatch $a, CandidateMatch $b): int => ($b->label === MatchLabel::Sterk) <=> ($a->label === MatchLabel::Sterk),
                fn (CandidateMatch $a, CandidateMatch $b): int => ($a->assignment->deadline_at?->getTimestamp() ?? PHP_INT_MAX) <=> ($b->assignment->deadline_at?->getTimestamp() ?? PHP_INT_MAX),
                fn (CandidateMatch $a, CandidateMatch $b): int => $b->score <=> $a->score,
            ])
            ->values();

        $dateLabel = $today->format('d-m-Y');

        foreach (array_filter($settings->digest_recipients, fn (string $email): bool => filter_var($email, FILTER_VALIDATE_EMAIL) !== false) as $email) {
            Mail::to($email)->send(new DailyDigestMail($matches->toBase(), $dateLabel));
        }

        if ($settings->digest_to_candidates) {
            foreach ($matches->groupBy('candidate_id') as $own) {
                $candidate = $own->first()?->candidate;

                if ($candidate !== null && filled($candidate->email)) {
                    Mail::to((string) $candidate->email)->send(new DailyDigestMail($own->toBase(), $dateLabel));
                }
            }
        }

        CandidateMatch::query()->whereKey($matches->modelKeys())->update(['digested_at' => now()]);
    }
}
