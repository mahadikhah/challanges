<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\TelegramUpdateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

/**
 * A webhook update, recorded before anything acts on it.
 *
 * @property int $id
 * @property int $update_id
 * @property array<string, mixed> $payload
 * @property CarbonImmutable|null $processed_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['update_id', 'payload', 'processed_at'])]
class TelegramUpdate extends Model
{
    /** @use HasFactory<TelegramUpdateFactory> */
    use HasFactory;

    /**
     * The update kinds we act on, in the order Telegram nests them.
     *
     * @var list<string>
     */
    public const array HANDLED_KINDS = [
        'message',
        'edited_message',
        'callback_query',
        'pre_checkout_query',
        'my_chat_member',
        'chat_member',
        'channel_post',
    ];

    public function isProcessed(): bool
    {
        return $this->processed_at !== null;
    }

    /**
     * Which kind of update this is — `message`, `callback_query`, and so on.
     *
     * Derived rather than stored: Telegram owns this vocabulary and adds to it,
     * and a column would need a migration to keep up.
     */
    public function kind(): ?string
    {
        foreach (self::HANDLED_KINDS as $kind) {
            if (isset($this->payload[$kind])) {
                return $kind;
            }
        }

        return null;
    }

    /**
     * Read a value out of the raw payload by dot path.
     *
     * The payload is whatever Telegram sent, so every access has to tolerate the
     * key being absent.
     */
    public function value(string $path, mixed $default = null): mixed
    {
        return Arr::get($this->payload, $path, $default);
    }

    /**
     * The Telegram id of whoever sent this update, if it came from a person.
     *
     * Read for *lookup only*. Trusting it for authorization would mean trusting
     * the request body; every surface re-resolves the actor server-side.
     */
    public function fromTelegramId(): ?int
    {
        $kind = $this->kind();

        if ($kind === null) {
            return null;
        }

        $id = $this->value("{$kind}.from.id");

        return is_int($id) ? $id : null;
    }

    /**
     * Updates recorded but not yet handled.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function unprocessed(Builder $query): void
    {
        $query->whereNull('processed_at')->orderBy('id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'update_id' => 'integer',
            'payload' => 'array',
            'processed_at' => 'datetime',
        ];
    }
}
