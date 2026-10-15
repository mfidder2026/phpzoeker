<?php

namespace App\Domain\Offers\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use LogicException;

/**
 * Onveranderbare vastlegging van een aanbieding zoals die is verstuurd (§11.7, R8). De app kent
 * geen update-acties; het model weigert wijzigen en verwijderen, en de database heeft een
 * trigger die `UPDATE` weigert.
 *
 * @property int $id
 * @property int $offer_id
 * @property Carbon $captured_at
 * @property int|null $captured_by
 * @property string $kind
 * @property array<string, mixed>|null $email_headers
 * @property string|null $email_html
 * @property string|null $email_text
 * @property string|null $eml_path
 * @property list<array{path: string, name: string, mime: string, size: int, sha256: string}>|null $attachments
 * @property array<string, mixed> $offer_data
 * @property array<string, mixed>|null $assessment
 * @property array<string, mixed> $assignment_data
 * @property array<string, mixed>|null $provenance
 * @property string $sha256
 * @property-read Offer $offer
 * @property-read User|null $capturedBy
 */
class OfferSnapshot extends Model
{
    public const KIND_EMAIL = 'sent_email';

    public const KIND_PLATFORM = 'platform_package';

    public const KIND_MANUAL = 'manual';

    public const DISK = 'local';

    /** Alleen de bewaartermijn-opschoning mag verwijderen (§11.7). */
    public static bool $allowPruning = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'captured_at' => 'datetime',
            'email_headers' => 'array',
            'attachments' => 'array',
            'offer_data' => 'array',
            'assessment' => 'array',
            'assignment_data' => 'array',
            'provenance' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (OfferSnapshot $snapshot): never {
            activity('aanbiedingen')->performedOn($snapshot->offer)->withProperties(['snapshot_id' => $snapshot->id])->log('Poging om een snapshot te wijzigen geweigerd');

            throw new LogicException('Een snapshot is onveranderbaar (R8).');
        });

        static::deleting(function (): void {
            if (! self::$allowPruning) {
                throw new LogicException('Een snapshot wordt alleen via de bewaartermijn verwijderd (R8).');
            }
        });
    }

    /**
     * @return BelongsTo<Offer, $this>
     */
    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function capturedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'captured_by');
    }

    /**
     * Canonieke inhoud waarover de hash gaat: alle velden plus de hash van het .eml-bestand.
     * De bijlagen staan er met hun eigen SHA-256 in.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function computeHash(array $attributes): string
    {
        $emlPath = $attributes['eml_path'] ?? null;
        $capturedAt = $attributes['captured_at'] ?? null;

        $canonical = [
            'offer_id' => (int) $attributes['offer_id'],
            'kind' => (string) $attributes['kind'],
            'captured_at' => $capturedAt instanceof Carbon ? $capturedAt->copy()->utc()->format('Y-m-d\TH:i:s\Z') : Carbon::parse((string) $capturedAt)->utc()->format('Y-m-d\TH:i:s\Z'),
            'email_headers' => $attributes['email_headers'] ?? null,
            'email_html' => $attributes['email_html'] ?? null,
            'email_text' => $attributes['email_text'] ?? null,
            'eml_sha256' => is_string($emlPath) && Storage::disk(self::DISK)->exists($emlPath) ? hash('sha256', (string) Storage::disk(self::DISK)->get($emlPath)) : null,
            'attachments' => $attributes['attachments'] ?? [],
            'offer_data' => $attributes['offer_data'],
            'assessment' => $attributes['assessment'] ?? null,
            'assignment_data' => $attributes['assignment_data'],
            'provenance' => $attributes['provenance'] ?? null,
        ];

        return hash('sha256', (string) json_encode(self::sortKeys($canonical), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Klopt de hash nog, en zijn de bestanden ongewijzigd?
     */
    public function isIntact(): bool
    {
        foreach ($this->attachments ?? [] as $file) {
            if (! Storage::disk(self::DISK)->exists($file['path']) || hash('sha256', (string) Storage::disk(self::DISK)->get($file['path'])) !== $file['sha256']) {
                return false;
            }
        }

        return hash_equals($this->sha256, self::computeHash([
            'offer_id' => $this->offer_id,
            'kind' => $this->kind,
            'captured_at' => $this->captured_at,
            'email_headers' => $this->email_headers,
            'email_html' => $this->email_html,
            'email_text' => $this->email_text,
            'eml_path' => $this->eml_path,
            'attachments' => $this->attachments,
            'offer_data' => $this->offer_data,
            'assessment' => $this->assessment,
            'assignment_data' => $this->assignment_data,
            'provenance' => $this->provenance,
        ]));
    }

    private static function sortKeys(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::sortKeys(...), $value);
    }
}
