<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\SettingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * An admin override of one App\Enums\SettingKey default.
 *
 * Read through App\Services\Settings rather than querying this model directly —
 * that is where the enum default fallback and the cache live.
 *
 * @property int $id
 * @property string $key
 * @property mixed $value
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['key', 'value'])]
class Setting extends Model
{
    /** @use HasFactory<SettingFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        // JSON keeps the PHP type intact on the way out, so an integer price
        // never comes back as the string "50".
        return [
            'value' => 'json',
        ];
    }
}
