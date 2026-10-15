<?php

namespace App\Domain\Offers\Actions;

use App\Domain\Candidates\Models\Candidate;
use App\Domain\Offers\Enums\OfferChannel;
use App\Domain\Offers\Enums\OfferStatus;
use App\Domain\Offers\Exceptions\DuplicateOfferException;
use App\Domain\Offers\Models\Offer;
use App\Domain\Relations\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Import van eerdere aanbiedingen uit een spreadsheet (FR-AR12) bij ingebruikname. Vaste
 * kolommen (zie {@see self::COLUMNS}); scheidingsteken puntkomma of komma. Elke regel loopt
 * door handmatig registreren, dus ook door de blokkade op dubbele aanbiedingen (R7).
 */
class ImportOffers
{
    /**
     * Kolommen: naam => verplicht.
     *
     * @var array<string, bool>
     */
    public const COLUMNS = [
        'aangeboden_op' => true,
        'kandidaat' => true,
        'opdracht' => true,
        'referentie' => false,
        'eindklant' => false,
        'via' => false,
        'contactpersoon' => false,
        'contact_email' => false,
        'kanaal' => false,
        'tarief' => true,
        'aangeboden_door' => false,
        'status' => false,
        'reactietermijn' => false,
        'notitie' => false,
    ];

    public function __construct(private readonly RecordManualOffer $recorder) {}

    /**
     * @return array{imported: int, skipped: list<string>}
     *
     * @throws RuntimeException als de kolommen niet kloppen
     */
    public function handle(string $path, User $user): array
    {
        $rows = self::read($path);
        $imported = 0;
        $skipped = [];

        foreach ($rows as $line => $row) {
            try {
                $data = $this->data($row, $user);

                if (self::alreadyImported($data)) {
                    $skipped[] = "Regel {$line}: staat al in het register (overgeslagen).";

                    continue;
                }

                $this->recorder->handle($data, $user);
                $imported++;
            } catch (DuplicateOfferException $exception) {
                $skipped[] = "Regel {$line}: {$exception->getMessage()} (overgeslagen).";
            } catch (Throwable $exception) {
                $skipped[] = "Regel {$line}: {$exception->getMessage()}";
            }
        }

        activity('aanbiedingen')->causedBy($user)->withProperties(['geimporteerd' => $imported, 'overgeslagen' => count($skipped)])->log('Eerdere aanbiedingen geïmporteerd');

        return ['imported' => $imported, 'skipped' => $skipped];
    }

    /**
     * @return array<int, array<string, string>> regelnummer => kolommen
     */
    public static function read(string $path): array
    {
        $content = (string) file_get_contents($path);
        $content = (string) preg_replace('/^\xEF\xBB\xBF/', '', $content);
        $lines = preg_split('/\r\n|\n|\r/', trim($content)) ?: [];

        if ($lines === [] || trim($lines[0]) === '') {
            throw new RuntimeException('Het bestand is leeg.');
        }

        $delimiter = substr_count($lines[0], ';') >= substr_count($lines[0], ',') ? ';' : ',';
        $header = array_map(fn (?string $column): string => Str::of((string) $column)->lower()->trim()->replace([' ', '-'], '_')->toString(), str_getcsv($lines[0], $delimiter, '"', '\\'));
        $missing = array_keys(array_filter(self::COLUMNS, fn (bool $required, string $column): bool => $required && ! in_array($column, $header, true), ARRAY_FILTER_USE_BOTH));

        if ($missing !== []) {
            throw new RuntimeException('Kolommen ontbreken: '.implode(', ', $missing).'. Verwacht: '.implode(';', array_keys(self::COLUMNS)).'.');
        }

        $rows = [];

        foreach (array_slice($lines, 1, preserve_keys: true) as $index => $line) {
            if (trim($line) === '') {
                continue;
            }

            $values = str_getcsv($line, $delimiter, '"', '\\');
            $rows[$index + 1] = array_combine($header, array_pad(array_map(fn (?string $value): string => trim((string) $value), $values), count($header), ''));
        }

        return $rows;
    }

    /**
     * @param  array<string, string>  $row
     * @return array<string, mixed>
     */
    private function data(array $row, User $user): array
    {
        $candidate = Candidate::query()->where('name', $row['kandidaat'])->orWhere('slug', Str::slug($row['kandidaat']))->first()
            ?? throw new RuntimeException("kandidaat \"{$row['kandidaat']}\" bestaat niet");

        $sentBy = filled($row['aangeboden_door'] ?? null)
            ? User::query()->where('name', $row['aangeboden_door'])->orWhere('email', $row['aangeboden_door'])->first()
            : null;

        $organization = filled($row['via'] ?? null)
            ? Organization::query()->where('normalized_name', Organization::normalizeName($row['via']))->first()
            : null;

        // "95", "92,50", "1.250,00" en "95.00" zijn allemaal geldig.
        $amount = str_replace(['€', ' '], '', $row['tarief']);
        $rate = (float) (str_contains($amount, ',') ? str_replace(['.', ','], ['', '.'], $amount) : $amount);

        if ($rate <= 0) {
            throw new RuntimeException("tarief \"{$row['tarief']}\" is geen bedrag");
        }

        return [
            'candidate_id' => $candidate->id,
            'new_title' => $row['opdracht'],
            'new_client' => $row['eindklant'] ?? null,
            'new_reference' => ($row['referentie'] ?? '') !== '' ? $row['referentie'] : null,
            'organization_id' => $organization?->id,
            'organization_name' => $organization === null && filled($row['via'] ?? null) ? $row['via'] : null,
            'contact_name' => ($row['contactpersoon'] ?? '') !== '' ? $row['contactpersoon'] : null,
            'contact_email' => filter_var($row['contact_email'] ?? '', FILTER_VALIDATE_EMAIL) !== false ? mb_strtolower($row['contact_email']) : null,
            'channel' => self::channel($row['kanaal'] ?? ''),
            'sent_at' => self::date($row['aangeboden_op'])->format('Y-m-d H:i'),
            'offered_rate' => $rate,
            'sent_by' => ($sentBy ?? $user)->id,
            'response_term_days' => is_numeric($row['reactietermijn'] ?? null) ? (int) $row['reactietermijn'] : null,
            'status' => self::status($row['status'] ?? ''),
            'note' => trim('Geïmporteerd. '.($row['notitie'] ?? '')),
        ];
    }

    /**
     * Dezelfde kandidaat, opdrachttitel en datum van aanbieden: een eerdere import van deze regel.
     *
     * @param  array<string, mixed>  $data
     */
    private static function alreadyImported(array $data): bool
    {
        return Offer::query()
            ->where('candidate_id', $data['candidate_id'])
            ->where('sent_at', CarbonImmutable::parse((string) $data['sent_at'], 'Europe/Amsterdam'))
            ->whereHas('assignment', fn ($query) => $query->where('title', $data['new_title']))
            ->exists();
    }

    private static function date(string $value): CarbonImmutable
    {
        foreach (['d-m-Y H:i', 'd-m-Y', 'Y-m-d H:i', 'Y-m-d', 'd/m/Y H:i', 'd/m/Y'] as $format) {
            try {
                $date = CarbonImmutable::createFromFormat($format, $value, 'Europe/Amsterdam');
            } catch (Throwable) {
                continue;
            }

            if ($date !== null && $date->format($format) === $value) {
                return str_contains($format, 'H') ? $date : $date->setTime(9, 0);
            }
        }

        throw new RuntimeException("datum \"{$value}\" is ongeldig (gebruik dd-mm-jjjj of dd-mm-jjjj uu:mm)");
    }

    private static function channel(string $value): string
    {
        return match (mb_strtolower(trim($value))) {
            'mail', 'e-mail', 'email' => OfferChannel::Email->value,
            'platform' => OfferChannel::Platform->value,
            default => OfferChannel::Manual->value,
        };
    }

    private static function status(string $value): string
    {
        $value = mb_strtolower(trim($value));

        foreach ([OfferStatus::Verzonden, OfferStatus::ReactieOntvangen, OfferStatus::IntakeGepland, OfferStatus::Geplaatst, OfferStatus::Afgewezen] as $status) {
            if ($value === $status->value || $value === mb_strtolower($status->getLabel())) {
                return $status->value;
            }
        }

        return match ($value) {
            'intake' => OfferStatus::IntakeGepland->value,
            'reactie' => OfferStatus::ReactieOntvangen->value,
            default => OfferStatus::Verzonden->value,
        };
    }
}
