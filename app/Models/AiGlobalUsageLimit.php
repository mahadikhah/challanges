<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * An explicit budget window, app-wide or per-owner.
 */
class AiGlobalUsageLimit extends Model
{
    protected $guarded = [];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'input_token_limit' => 'integer',
        'output_token_limit' => 'integer',
        'total_token_limit' => 'integer',
        'is_active' => 'boolean',
    ];

    /**
     * @return MorphTo<Model, $this>
     */
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }
}
