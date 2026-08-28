<?php

namespace App\Models;

use App\Enums\AiCapabilityPurpose;
use Database\Factories\AiCapabilityFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One AI function the app needs — the unit accounts hang underneath.
 *
 * No credentials here. The seeded rows are **fixed**: code references the keys
 * as constants, so new functions mean a migration, never a runtime insert.
 */
class AiCapability extends Model
{
    /** @use HasFactory<AiCapabilityFactory> */
    use HasFactory;

    public const KEY_CRITERIA_GENERATION = 'criteria_generation';

    public const KEY_CRITERIA_SCREENING = 'criteria_screening';

    public const KEY_PROOF_MODERATION = 'proof_moderation';

    protected $guarded = [];

    protected $casts = [
        'purpose' => AiCapabilityPurpose::class,
        'is_active' => 'boolean',
    ];

    /**
     * @return HasMany<AiProviderAccount, $this>
     */
    public function accounts(): HasMany
    {
        return $this->hasMany(AiProviderAccount::class);
    }
}
