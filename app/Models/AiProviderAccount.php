<?php

namespace App\Models;

use App\Enums\AiLimitPeriod;
use App\Services\Ai\AiDriverCatalog;
use Carbon\CarbonImmutable;
use Database\Factories\AiProviderAccountFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The rotatable unit: one credential set (encrypted, in `config` — never
 * `.env`) plus what happened last time it was used.
 *
 * @property AiLimitPeriod|null $limit_period
 */
class AiProviderAccount extends Model
{
    /** @use HasFactory<AiProviderAccountFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'config' => 'encrypted:array',
            'input_token_limit' => 'integer',
            'output_token_limit' => 'integer',
            'total_token_limit' => 'integer',
            'limit_period' => AiLimitPeriod::class,
            'input_token_price_per_million' => 'integer',
            'output_token_price_per_million' => 'integer',
            'unavailable_until' => 'datetime',
            'last_failed_at' => 'datetime',
            'last_succeeded_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<AiCapability, $this>
     */
    public function capability(): BelongsTo
    {
        return $this->belongsTo(AiCapability::class, 'ai_capability_id');
    }

    /**
     * @return HasMany<AiUsageRecord, $this>
     */
    public function usageRecords(): HasMany
    {
        return $this->hasMany(AiUsageRecord::class);
    }

    /**
     * Switched on, under a capability that is itself switched on. Both halves
     * matter — this is the candidate list, not the callable list.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeUsable(Builder $query): Builder
    {
        return $query->where('ai_provider_accounts.is_active', true)
            ->whereHas('capability', fn (Builder $q): Builder => $q->where('is_active', true))
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    /**
     * Usable accounts not serving out a cooldown. The chain is built from this.
     * The usable() conditions are repeated inline rather than chained — a
     * scope name is invisible to static analysis, and this scope must stay
     * provably the usable() list minus the cooldown clause.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeAvailable(Builder $query): Builder
    {
        return $query
            ->where('ai_provider_accounts.is_active', true)
            ->whereHas('capability', fn (Builder $q): Builder => $q->where('is_active', true))
            ->where(fn (Builder $q): Builder => $q
                ->whereNull('unavailable_until')
                ->orWhere('unavailable_until', '<=', now()))
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function isCoolingDown(): bool
    {
        return $this->unavailable_until !== null
            && CarbonImmutable::parse($this->unavailable_until)->isFuture();
    }

    /**
     * Complete enough to call. Says nothing about either is_active switch: an
     * account can be complete and deliberately parked, and reporting a
     * well-filled account as "unconfigured" because its capability is off
     * sends the operator hunting for a credential that is already there.
     */
    public function isConfigured(): bool
    {
        $driver = is_string($this->driver) ? trim($this->driver) : '';

        if ($driver === '') {
            return false;
        }

        $config = (array) ($this->config ?? []);

        foreach (AiDriverCatalog::requiredKeys($driver) as $key) {
            if (blank($config[$key] ?? null)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Healthy again, whatever it did last time.
     */
    public function markSucceeded(): void
    {
        $this->update([
            'last_succeeded_at' => now(),
            'unavailable_until' => null,
            'last_failure_reason' => null,
        ]);
    }

    public function markUnavailable(string $reason, int $minutes): void
    {
        $this->update([
            'last_failed_at' => now(),
            'last_failure_reason' => $reason,
            'unavailable_until' => now()->addMinutes($minutes),
        ]);
    }
}
