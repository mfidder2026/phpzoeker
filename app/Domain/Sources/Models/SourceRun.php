<?php

namespace App\Domain\Sources\Models;

use App\Domain\Sources\Enums\SourceRunStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Eén run van een bron (FR-B3): start, einde, aantallen en log.
 *
 * @property int $id
 * @property int $source_id
 * @property bool $dry_run
 * @property SourceRunStatus $status
 * @property Carbon $started_at
 * @property Carbon|null $finished_at
 * @property int $fetched_count
 * @property int $new_count
 * @property int $error_count
 * @property string|null $log
 * @property-read Source $source
 */
class SourceRun extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'dry_run' => 'boolean',
            'status' => SourceRunStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'fetched_count' => 'integer',
            'new_count' => 'integer',
            'error_count' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Source, $this>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    public function appendLog(string $line): void
    {
        $this->log = trim(($this->log ?? '')."\n".now()->format('H:i:s').' '.mb_substr($line, 0, 500));
    }
}
