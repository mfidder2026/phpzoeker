<?php

namespace App\Ai\Config;

use App\Ai\Providers\ProviderName;
use App\Ai\Runs\AiTask;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Configuratie van de AI-agent (§18.5). De API-sleutel staat versleuteld in de database en
 * komt nooit volledig terug naar de UI, logs of exports (R9).
 *
 * @property int $id
 * @property string $name
 * @property bool $is_active
 * @property bool $is_fallback
 * @property ProviderName $provider
 * @property string|null $api_key
 * @property string|null $api_key_last4
 * @property string|null $base_url
 * @property array<string, string>|null $extra_options
 * @property array<string, string> $models
 * @property array<string, int>|null $max_output_tokens
 * @property int $timeout_seconds
 * @property int $max_concurrency
 * @property string $daily_budget_eur
 * @property array<string, array{input: float|int|string, output: float|int|string}>|null $prices
 * @property bool $web_research_enabled
 * @property list<string>|null $web_allowed_domains
 * @property string|null $web_test_url
 * @property Carbon|null $dpa_confirmed_at
 * @property Carbon|null $last_test_at
 * @property array<string, mixed>|null $last_test_result
 * @property int|null $updated_by
 */
class AiAgentProfile extends Model
{
    use LogsActivity;

    protected $guarded = ['id', 'api_key_last4'];

    /**
     * @var list<string>
     */
    protected $hidden = ['api_key', 'extra_options'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_fallback' => 'boolean',
            'provider' => ProviderName::class,
            'api_key' => 'encrypted',
            'extra_options' => 'encrypted:array',
            'models' => 'array',
            'max_output_tokens' => 'array',
            'timeout_seconds' => 'integer',
            'max_concurrency' => 'integer',
            'daily_budget_eur' => 'decimal:2',
            'prices' => 'array',
            'web_research_enabled' => 'boolean',
            'web_allowed_domains' => 'array',
            'dpa_confirmed_at' => 'datetime',
            'last_test_at' => 'datetime',
            'last_test_result' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (AiAgentProfile $profile): void {
            if ($profile->isDirty('api_key')) {
                $key = (string) $profile->api_key;
                $profile->api_key_last4 = $key === '' ? null : mb_substr($key, -4);
            }

            if ($profile->is_active && $profile->dpa_confirmed_at === null) {
                throw new \DomainException('Een AI-agentprofiel kan pas actief worden als de verwerkersovereenkomst is bevestigd.');
            }
        });

        static::saved(function (AiAgentProfile $profile): void {
            if ($profile->is_active) {
                static::query()->whereKeyNot($profile->getKey())->where('is_active', true)->update(['is_active' => false]);
            }
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('ai-agent')
            ->logAll()
            ->logExcept(['api_key', 'extra_options', 'last_test_result', 'created_at', 'updated_at'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public static function active(): ?self
    {
        return static::query()->where('is_active', true)->first();
    }

    public function maskedApiKey(): string
    {
        return $this->api_key_last4 === null ? '' : '••••••••'.$this->api_key_last4;
    }

    public function modelFor(AiTask $task): string
    {
        if ($task === AiTask::ConnectionTest) {
            $task = AiTask::Extract;
        }

        return (string) ($this->models[$task->value] ?? '');
    }

    public function maxOutputTokensFor(AiTask $task): int
    {
        return (int) ($this->max_output_tokens[$task->value] ?? $task->defaultMaxOutputTokens());
    }

    /**
     * Geschatte kosten in euro's op basis van de handmatig bijgehouden prijzen per 1 mln. tokens.
     */
    public function estimateCost(string $model, int $inputTokens, int $outputTokens): float
    {
        $price = $this->prices[$model] ?? $this->prices['*'] ?? null;

        if ($price === null) {
            return 0.0;
        }

        return round(
            ($inputTokens * (float) $price['input'] + $outputTokens * (float) $price['output']) / 1_000_000,
            5,
        );
    }

    public function resolvedBaseUrl(): string
    {
        $url = $this->base_url !== null && $this->base_url !== '' ? $this->base_url : $this->provider->defaultBaseUrl();

        return rtrim($url, '/');
    }
}
