<?php

namespace App\Models;

use App\Enums\AiReservationStatus;
use Database\Factories\AiUsageReservationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * The token lease held across a provider call: reserved at an estimate before
 * the HTTP call, swapped for real usage (or released) after it.
 */
class AiUsageReservation extends Model
{
    /** @use HasFactory<AiUsageReservationFactory> */
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'status' => AiReservationStatus::class,
        'usage_known' => 'boolean',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'failed_at' => 'datetime',
        'released_at' => 'datetime',
        'reconciled_at' => 'datetime',
        'terminal_at' => 'datetime',
    ];

    /**
     * @return MorphTo<Model, $this>
     */
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<AiProviderAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(AiProviderAccount::class, 'ai_provider_account_id');
    }

    /**
     * @return BelongsTo<AiCapability, $this>
     */
    public function capability(): BelongsTo
    {
        return $this->belongsTo(AiCapability::class, 'ai_capability_id');
    }

    /**
     * @return BelongsTo<AiUsageRecord, $this>
     */
    public function usageRecord(): BelongsTo
    {
        return $this->belongsTo(AiUsageRecord::class, 'ai_usage_record_id');
    }
}
