<?php

namespace App\Domain\Offers\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Getailord CV bij een aanbieding (§12). `content` heeft de structuur van Bijlage D plus
 * `aansluiting_op_opdracht`. Een goedgekeurde versie is bevroren.
 *
 * @property int $id
 * @property int $offer_id
 * @property int $version
 * @property string $language
 * @property int|null $profile_version
 * @property array<string, mixed> $content
 * @property list<array<string, mixed>>|null $changes
 * @property string|null $docx_path
 * @property string|null $pdf_path
 * @property array<string, mixed>|null $diff_summary
 * @property array<string, mixed>|null $grounding_report
 * @property bool $grounding_passed
 * @property bool $edited
 * @property int|null $approved_by
 * @property Carbon|null $approved_at
 * @property string|null $model
 * @property string|null $prompt_version
 * @property string|null $ai_run_id
 * @property-read Offer $offer
 * @property-read User|null $approvedBy
 */
class TailoredCv extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'content' => 'array',
            'changes' => 'array',
            'diff_summary' => 'array',
            'grounding_report' => 'array',
            'grounding_passed' => 'boolean',
            'edited' => 'boolean',
            'approved_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (TailoredCv $cv): void {
            if ($cv->getOriginal('approved_at') !== null && $cv->isDirty(['content', 'docx_path', 'pdf_path'])) {
                throw new LogicException('Een goedgekeurd CV is bevroren; maak een nieuwe versie.');
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
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isApproved(): bool
    {
        return $this->approved_at !== null;
    }
}
