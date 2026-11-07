<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\SchedulerHeartbeatFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * When the scheduler last proved it was alive.
 *
 * One row per heartbeat source (today only the scheduler's own
 * `observability:heartbeat` command, keyed `scheduler`), stamped every minute
 * by that command. Readers compare `last_ran_at` against the
 * `heartbeat_staleness_minutes` Setting to decide whether cron is still
 * running — nothing inside this application can detect cron dying completely;
 * only the optional external ping (§2.10) can do that, and this row is what
 * tells it we're still worth pinging for.
 *
 * @property int $id
 * @property string $key
 * @property CarbonImmutable $last_ran_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['key', 'last_ran_at'])]
class SchedulerHeartbeat extends Model
{
    /** @use HasFactory<SchedulerHeartbeatFactory> */
    use HasFactory;

    /**
     * The scheduler's own heartbeat — the one row this table exists for.
     */
    public const string SCHEDULER_KEY = 'scheduler';

    protected static function booted(): void
    {
        static::creating(function (self $heartbeat): void {
            $heartbeat->key ??= self::SCHEDULER_KEY;
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_ran_at' => 'immutable_datetime',
        ];
    }
}
