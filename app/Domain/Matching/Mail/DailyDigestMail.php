<?php

namespace App\Domain\Matching\Mail;

use App\Domain\Matching\Models\CandidateMatch;
use App\Filament\Resources\Matches\MatchResource;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

/**
 * Dagelijkse digest (FR-N1, §14): nieuwe sterke en mogelijke matches per kandidaat, gesorteerd
 * op deadline en score, met per match één regel uitleg en een link.
 */
class DailyDigestMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  Collection<int, CandidateMatch>  $matches
     */
    public function __construct(public Collection $matches, public string $dateLabel) {}

    public function envelope(): Envelope
    {
        $count = $this->matches->count();

        return new Envelope(subject: "[PHP Zoeker] Digest {$this->dateLabel}: ".($count === 0 ? 'geen nieuwe matches' : $count.' '.($count === 1 ? 'nieuwe match' : 'nieuwe matches')));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.daily-digest', with: [
            'groups' => $this->matches->groupBy(fn (CandidateMatch $match): string => $match->candidate->name)->sortKeys(),
            'url' => fn (CandidateMatch $match): string => MatchResource::getUrl('view', ['record' => $match]),
            'matchesUrl' => MatchResource::getUrl(),
        ]);
    }
}
