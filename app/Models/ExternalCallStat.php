<?php

namespace App\Models;

use App\Enums\ExternalCallOutcome;
use App\Enums\ExternalCallProvider;
use Carbon\CarbonImmutable;
use Database\Factories\ExternalCallStatFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One provider's success or failure count for one day.
 *
 * Written only through App\Actions\Observability\RecordExternalCall, which
 * increments atomically; read by the System Health page over a rolling
 * window. Read the §3.9 note on the migration for why this table exists at
 * all when Telescope also watches the outbound calls.
 *
 * @property int $id
 * @property ExternalCallProvider $provider
 * @property CarbonImmutable $day
 * @property ExternalCallOutcome $outcome
 * @property int $count
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['provider', 'day', 'outcome', 'count'])]
class ExternalCallStat extends Model
{
    /** @use HasFactory<ExternalCallStatFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => ExternalCallProvider::class,
            'day' => 'immutable_date',
            'outcome' => ExternalCallOutcome::class,
        ];
    }
}
