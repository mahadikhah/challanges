<?php

namespace App\Models;

use App\Enums\AiUsageOutcome;
use App\Enums\AiUsageQuality;
use App\Enums\AiUsageRecordStatus;
use Database\Factories\AiUsageRecordFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

/**
 * The append-only ledger: one row per provider attempt, success or failure.
 * Terminal rows refuse updates and deletes — they are accounting.
 */
class AiUsageRecord extends Model
{
    /** @use HasFactory<AiUsageRecordFactory> */
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'outcome' => AiUsageOutcome::class,
        'status' => AiUsageRecordStatus::class,
        'usage_quality' => AiUsageQuality::class,
        'metadata' => 'array',
        'provider_called_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // Read the RAW ORIGINAL status, not the new value — otherwise an
        // update that also changes status slips past the guard.
        static::updating(function (self $record): void {
            $original = $record->getRawOriginal('status');

            if (is_string($original)) {
                $original = AiUsageRecordStatus::from($original);
            }

            if ($original?->isTerminal()) {
                throw new LogicException('Terminal AI usage records are append-only.');
            }
        });

        static::deleting(function (self $record): void {
            $status = $record->getRawOriginal('status');

            if (is_string($status)) {
                $status = AiUsageRecordStatus::tryFrom($status);
            }

            if ($status !== null && $status->isTerminal()) {
                throw new LogicException('AI usage records are append-only.');
            }
        });
    }

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
     * @return BelongsTo<AiCapability, $this>
     */
    public function capability(): BelongsTo
    {
        return $this->belongsTo(AiCapability::class, 'ai_capability_id');
    }

    /**
     * @return BelongsTo<AiProviderAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(AiProviderAccount::class, 'ai_provider_account_id');
    }
}
