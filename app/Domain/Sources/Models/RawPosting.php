<?php

namespace App\Domain\Sources\Models;

use App\Domain\Assignments\Models\Assignment;
use App\Domain\Sources\Enums\RawPostingStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Ruwe, onbewerkte publicatie uit één bron (§4).
 *
 * @property int $id
 * @property int $source_id
 * @property int|null $parent_id
 * @property string $external_id
 * @property string|null $url
 * @property string $title
 * @property string|null $body_text
 * @property string|null $body_html
 * @property array<string, mixed>|null $meta
 * @property string $content_hash
 * @property Carbon|null $published_at
 * @property Carbon $received_at
 * @property Carbon|null $processed_at
 * @property RawPostingStatus $status
 * @property int|null $assignment_id
 * @property string|null $processing_error
 * @property-read Source $source
 * @property-read Assignment|null $assignment
 */
class RawPosting extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'published_at' => 'datetime',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
            'status' => RawPostingStatus::class,
        ];
    }

    /**
     * @return BelongsTo<Source, $this>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    /**
     * @return BelongsTo<Assignment, $this>
     */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class);
    }

    /**
     * @return BelongsTo<RawPosting, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(RawPosting::class, 'parent_id');
    }

    /**
     * Hash over de genormaliseerde tekst: dezelfde publicatie = dezelfde hash (§9.4, regel 1).
     */
    public static function hashContent(string $title, ?string $body): string
    {
        $text = mb_strtolower($title.' '.($body ?? ''));
        $text = (string) preg_replace('/\s+/u', ' ', $text);

        return hash('sha256', trim($text));
    }
}
