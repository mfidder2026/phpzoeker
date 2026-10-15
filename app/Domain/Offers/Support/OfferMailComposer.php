<?php

namespace App\Domain\Offers\Support;

use App\Domain\Assignments\Enums\WorkMode;
use App\Domain\Candidates\Enums\AbsenceType;
use App\Domain\Candidates\Models\CandidateAbsence;
use App\Domain\Matching\Support\HardFilters;
use App\Domain\Offers\Models\Offer;
use App\Settings\MailSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

/**
 * Bouwt de aanbiedingsmail (§13.3, Bijlage E). Alleen `intro` en `bullets` komen van het
 * taalmodel; onderwerp, aanhef, praktische gegevens en handtekening komen uit data en de
 * Blade-templates, zodat er geen tarief, datum of ontvanger verzonnen kan worden.
 */
class OfferMailComposer
{
    public function __construct(private readonly MailSettings $settings) {}

    public function subject(Offer $offer, string $cvTitle): string
    {
        $assignment = $offer->assignment;
        $prefix = $assignment->language === 'en' ? 'Proposal' : 'Aanbieding';

        return "{$prefix} {$offer->candidate->name} – ".($cvTitle !== '' ? $cvTitle : $assignment->title).' – '.($assignment->reference ?: $assignment->title);
    }

    public function greeting(Offer $offer): string
    {
        $english = $offer->assignment->language === 'en';
        $name = $offer->contact->name ?? $offer->assignmentSource->contact_name ?? null;

        if (filled($name)) {
            return ($english ? 'Dear ' : 'Beste ').$name.',';
        }

        if (filled($offer->organization?->name)) {
            return ($english ? 'Dear ' : 'Beste ').$offer->organization->name.',';
        }

        return $english ? 'Dear Sir or Madam,' : 'Geachte heer/mevrouw,';
    }

    /**
     * Praktische regels: beschikbaarheid (met afwezigheden in de looptijd), tarief, woonplaats en
     * eventueel rijbewijs.
     *
     * @return list<string>
     */
    public function practicalLines(Offer $offer): array
    {
        $candidate = $offer->candidate;
        $assignment = $offer->assignment;
        $english = $assignment->language === 'en';
        $snapshot = $offer->candidate_snapshot ?? $candidate->offerSnapshot();
        $availableFrom = $snapshot['available_from'] ?? null;
        $hours = (int) ($snapshot['hours_per_week'] ?? $candidate->hours_per_week);

        $availability = $availableFrom === null || CarbonImmutable::parse($availableFrom)->isPast()
            ? ($english ? 'immediately' : 'per direct')
            : ($english ? 'from ' : 'vanaf ').CarbonImmutable::parse($availableFrom)->format('d-m-Y');

        $line = ($english ? 'Available: ' : 'Beschikbaar: ').$availability.', '.$hours.($english ? ' hours per week' : ' uur per week');
        $absences = $this->absencesDuring($offer);

        if ($absences !== []) {
            $line .= $english
                ? ' (except for '.implode(' and ', $absences).')'
                : ' (met uitzondering van '.implode(' en ', $absences).')';
        }

        $lines = [$line, ($english ? 'Rate: ' : 'Tarief: ').self::rate((float) ($offer->offered_rate ?? $candidate->hourly_rate)).($english ? ' per hour, excluding VAT' : ' per uur, exclusief btw')];

        if (filled($candidate->city)) {
            $lines[] = ($english ? 'Place of residence: ' : 'Woonplaats: ').$candidate->city;
        }

        if (in_array($assignment->work_mode, [WorkMode::Onsite, WorkMode::Hybride], true) && $candidate->driving_license) {
            $lines[] = $english ? "Driving licence B{$this->ownTransport($candidate->own_transport, true)}" : "Rijbewijs B{$this->ownTransport($candidate->own_transport, false)}";
        }

        return $lines;
    }

    /**
     * Afwezigheden die in de looptijd vallen, bijv. "een geplande vakantie van 18 t/m 31 december 2026".
     *
     * @return list<string>
     */
    public function absencesDuring(Offer $offer): array
    {
        [$start, $end] = HardFilters::period($offer->assignment);
        $overlap = HardFilters::absenceOverlap($offer->candidate, $start, $end);
        $absences = $overlap['full'] !== null ? [$overlap['full'], ...$overlap['partial']] : $overlap['partial'];
        $english = $offer->assignment->language === 'en';

        return array_map(function (CandidateAbsence $absence) use ($english): string {
            $type = $absence->type === AbsenceType::Overig ? 'afwezigheid' : mb_strtolower($absence->type->getLabel());

            return $english
                ? "a planned {$this->englishAbsence($type)} from ".self::period($absence->starts_on, $absence->ends_on, 'en')
                : "een geplande {$type} van ".self::period($absence->starts_on, $absence->ends_on, 'nl');
        }, $absences);
    }

    /**
     * "18 t/m 31 december 2026", of "28 december 2026 t/m 3 januari 2027".
     */
    public static function period(Carbon $from, Carbon $until, string $language = 'nl'): string
    {
        $locale = $language === 'en' ? 'en' : 'nl';
        $from = CarbonImmutable::parse($from->toDateString())->settings(['locale' => $locale]);
        $until = CarbonImmutable::parse($until->toDateString())->settings(['locale' => $locale]);
        $separator = $language === 'en' ? ' to ' : ' t/m ';

        if ($from->isSameMonth($until)) {
            return $from->format('j').$separator.$until->translatedFormat('j F Y');
        }

        if ($from->isSameYear($until)) {
            return $from->translatedFormat('j F').$separator.$until->translatedFormat('j F Y');
        }

        return $from->translatedFormat('j F Y').$separator.$until->translatedFormat('j F Y');
    }

    public static function safeFileName(string $name): string
    {
        $name = trim((string) preg_replace('#[\\\\/:*?"<>|\x00-\x1F]+#', '-', $name));

        return $name !== '' ? mb_substr($name, 0, 150) : 'bestand';
    }

    /**
     * "€ 95" of "€ 87,50".
     */
    public static function rate(float $rate): string
    {
        return '€ '.(fmod($rate, 1.0) === 0.0 ? number_format($rate, 0, ',', '.') : number_format($rate, 2, ',', '.'));
    }

    /**
     * @param  list<string>  $bullets
     * @return array{html: string, text: string}
     */
    public function body(Offer $offer, string $intro, array $bullets): array
    {
        $data = [
            'greeting' => $this->greeting($offer),
            'intro' => $intro,
            'bullets' => $bullets,
            'practical' => $this->practicalLines($offer),
            'candidateFirstName' => explode(' ', $offer->candidate->name)[0],
            'english' => $offer->assignment->language === 'en',
            'signatureHtml' => $this->settings->signature_html,
            'signatureText' => $this->settings->signature_text,
            'senderName' => $this->settings->sender_name,
        ];

        return [
            'html' => view('mail.offer.html', $data)->render(),
            'text' => trim(view('mail.offer.text', $data)->render())."\n",
        ];
    }

    private function ownTransport(?bool $ownTransport, bool $english): string
    {
        return $ownTransport ? ($english ? ' and own car' : ' en eigen auto') : '';
    }

    private function englishAbsence(string $type): string
    {
        return match ($type) {
            'vakantie' => 'holiday',
            'verlof' => 'leave',
            default => 'absence',
        };
    }
}
