<?php

namespace App\Domain\Relations\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Contactpersoon bij een opdrachtgever of intermediair, zoals gepubliceerd in de aanvraag (§19.2).
 *
 * @property int $id
 * @property int|null $organization_id
 * @property string|null $name
 * @property string|null $role
 * @property string|null $email
 * @property string|null $phone
 * @property Carbon|null $last_used_at
 */
class Contact extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'phone' => 'encrypted',
            'last_used_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
