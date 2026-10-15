<?php

namespace App\Domain\Relations\Models;

use App\Domain\Relations\Enums\OrganizationType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Opdrachtgever, intermediair of platform (§11.9). In fase 2 aangemaakt of herkend bij
 * extractie; het volledige relatiebeheer volgt in fase 4.
 *
 * @property int $id
 * @property string $name
 * @property string $normalized_name
 * @property OrganizationType $type
 * @property string|null $website
 * @property list<string>|null $email_domains
 * @property int|null $default_response_term_days
 * @property int|null $merged_into_id
 */
class Organization extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'type' => OrganizationType::class,
            'email_domains' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Organization $organization): void {
            $organization->normalized_name = self::normalizeName($organization->name);
        });
    }

    /**
     * "Gemeente Vlaardingen B.V." → "gemeente vlaardingen"; rechtsvormen en leestekens vallen weg.
     */
    public static function normalizeName(string $name): string
    {
        $name = Str::of($name)->ascii()->lower()->replaceMatches('/[^a-z0-9 ]+/', ' ')->squish()->toString();
        $name = (string) preg_replace('/\b(b ?v|n ?v|bv|nv|vof|holding|group|groep|nederland|the netherlands)\b/', '', $name);

        return trim((string) preg_replace('/\s+/', ' ', $name));
    }

    /**
     * @return HasMany<Contact, $this>
     */
    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }
}
