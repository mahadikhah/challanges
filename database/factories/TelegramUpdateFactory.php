<?php

namespace Database\Factories;

use App\Enums\MessagingPlatform;
use App\Models\TelegramUpdate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TelegramUpdate>
 */
class TelegramUpdateFactory extends Factory
{
    /**
     * Define the model's default state: an unprocessed `/start` message, as
     * Telegram delivers it.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'platform' => MessagingPlatform::Telegram,
            'update_id' => fake()->unique()->numberBetween(1_000_000, 9_999_999),
            'payload' => $this->messagePayload('/start'),
            'processed_at' => null,
        ];
    }

    /**
     * The same shapes, arriving on Bale.
     *
     * `update_id` and chat ids are platform-issued and can collide numerically
     * across messengers, which is exactly what the `(platform, update_id)`
     * uniqueness exists for; this state lets a test prove it.
     */
    public function bale(): static
    {
        return $this->state(fn (array $attributes): array => [
            'platform' => MessagingPlatform::Bale,
        ]);
    }

    /**
     * Pin the update id, to test that a replay collides.
     */
    public function withUpdateId(int $updateId): static
    {
        return $this->state(fn (array $attributes): array => [
            'update_id' => $updateId,
        ]);
    }

    public function processed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'processed_at' => now(),
        ]);
    }

    public function message(string $text, ?int $fromTelegramId = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'payload' => $this->messagePayload($text, $fromTelegramId),
        ]);
    }

    /**
     * A message from a sender described field by field, for the profile fields
     * `ResolveTelegramUser` reads — username, last name, client language.
     *
     * @param  array<string, mixed>  $from  merged over Telegram's `from` object
     */
    public function messageFrom(array $from, string $text = '/start'): static
    {
        return $this->state(fn (array $attributes): array => [
            'payload' => $this->messagePayload(
                $text,
                is_int($from['id'] ?? null) ? $from['id'] : null,
                $from,
            ),
        ]);
    }

    /**
     * The same message, arriving from somewhere that is not a private chat.
     *
     * The bot is an admin of the announcement channel and can be added to groups,
     * so this shape is real traffic rather than a hypothetical.
     */
    public function messageInChat(string $chatType, string $text = '/start'): static
    {
        return $this->state(fn (array $attributes): array => [
            'payload' => $this->messagePayload($text, null, [], $chatType),
        ]);
    }

    /**
     * A photo message from a sender described field by field.
     *
     * A photo has no `text`, which is the whole point: it exercises the paths that
     * must not treat "no text" as "nothing". Telegram sends an array of sizes
     * smallest-first with a `file_id` on each, so this builds the same ladder.
     *
     * @param  array<string, mixed>  $from  merged over Telegram's `from` object
     */
    public function photoFrom(array $from, string $fileId = 'AgACphoto-file-id'): static
    {
        return $this->state(function (array $attributes) use ($from, $fileId): array {
            $payload = $this->messagePayload('', null, $from);
            unset($payload['message']['text']);
            $payload['message']['photo'] = [
                ['file_id' => $fileId.'-small', 'width' => 160, 'height' => 160],
                ['file_id' => $fileId, 'width' => 1280, 'height' => 960],
            ];

            return ['payload' => $payload];
        });
    }

    /**
     * A voice message from a sender described field by field.
     *
     * Voice carries no `text`; the step flow reads `file_id` for the download
     * and `duration` for the step's cap — the duration Telegram itself
     * measured, which is exactly why the factory takes it as a parameter
     * rather than faking a constant.
     *
     * @param  array<string, mixed>  $from  merged over Telegram's `from` object
     */
    public function voiceFrom(array $from, int $duration = 30, string $fileId = 'AwVoice-file-id', ?int $fileSize = null): static
    {
        return $this->state(function (array $attributes) use ($from, $duration, $fileId, $fileSize): array {
            $payload = $this->messagePayload('', null, $from);
            unset($payload['message']['text']);
            $payload['message']['voice'] = [
                'duration' => $duration,
                'mime_type' => 'audio/ogg',
                'file_id' => $fileId,
            ];

            if ($fileSize !== null) {
                $payload['message']['voice']['file_size'] = $fileSize;
            }

            return ['payload' => $payload];
        });
    }

    /**
     * A video message from a sender described field by field.
     *
     * Same shape as voice: `file_id` for the download, `duration` and
     * `file_size` for the caps — both measured by the messenger, which is the
     * point of taking them as parameters.
     *
     * @param  array<string, mixed>  $from  merged over Telegram's `from` object
     */
    public function videoFrom(array $from, int $duration = 20, string $fileId = 'BaVideo-file-id', ?int $fileSize = null): static
    {
        return $this->state(function (array $attributes) use ($from, $duration, $fileId, $fileSize): array {
            $payload = $this->messagePayload('', null, $from);
            unset($payload['message']['text']);
            $payload['message']['video'] = [
                'duration' => $duration,
                'mime_type' => 'video/mp4',
                'file_id' => $fileId,
            ];

            if ($fileSize !== null) {
                $payload['message']['video']['file_size'] = $fileSize;
            }

            return ['payload' => $payload];
        });
    }

    public function callbackQuery(string $data, ?int $fromTelegramId = null): static
    {
        return $this->callbackQueryFrom(
            $fromTelegramId === null ? [] : ['id' => $fromTelegramId],
            $data,
        );
    }

    /**
     * A tap from a sender described field by field.
     *
     * The sender matters more here than it looks: `ResolveTelegramUser` refreshes
     * the profile columns from every `from` it sees, so a `callback_query` carrying
     * only an id would blank the first name and client language a `message` had just
     * recorded. Telegram always sends the full object, so the default does too.
     *
     * @param  array<string, mixed>  $from  merged over Telegram's `from` object
     */
    public function callbackQueryFrom(array $from, string $data): static
    {
        return $this->state(function (array $attributes) use ($from, $data): array {
            $telegramId = is_int($from['id'] ?? null) ? $from['id'] : fake()->numberBetween(1, 999_999);

            return [
                'payload' => [
                    'callback_query' => [
                        'id' => (string) fake()->numberBetween(1000, 9999),
                        'from' => array_replace([
                            'id' => $telegramId,
                            'is_bot' => false,
                            'first_name' => fake()->firstName(),
                        ], $from),
                        'message' => [
                            'message_id' => fake()->numberBetween(1, 9999),
                            'chat' => ['id' => $telegramId, 'type' => 'private'],
                        ],
                        'data' => $data,
                    ],
                ],
            ];
        });
    }

    public function preCheckoutQuery(string $invoicePayload, int $stars = 25, ?int $fromTelegramId = null, string $currency = 'XTR'): static
    {
        return $this->state(fn (array $attributes): array => [
            'payload' => [
                'pre_checkout_query' => [
                    'id' => (string) fake()->numberBetween(1000, 9999),
                    'from' => [
                        'id' => $fromTelegramId ?? fake()->numberBetween(1, 999_999),
                        'is_bot' => false,
                        'first_name' => fake()->firstName(),
                    ],
                    'currency' => $currency,
                    'total_amount' => $stars,
                    'invoice_payload' => $invoicePayload,
                ],
            ],
        ]);
    }

    /**
     * A completed payment, as Telegram delivers it: a message with no text and
     * a `successful_payment` object riding where the text would be.
     *
     * @param  array<string, mixed>  $from  merged over the default sender
     */
    public function successfulPaymentFrom(array $from, string $invoicePayload, string $chargeId, int $stars, string $currency = 'XTR'): static
    {
        return $this->state(function (array $attributes) use ($from, $invoicePayload, $chargeId, $stars, $currency): array {
            $telegramId = is_int($from['id'] ?? null) ? $from['id'] : fake()->numberBetween(1, 999_999);

            return [
                'payload' => [
                    'message' => [
                        'message_id' => fake()->numberBetween(1, 9999),
                        'from' => array_replace([
                            'id' => $telegramId,
                            'is_bot' => false,
                            'first_name' => fake()->firstName(),
                        ], $from),
                        'chat' => ['id' => $telegramId, 'type' => 'private'],
                        'date' => 1_760_000_000,
                        'successful_payment' => [
                            'currency' => $currency,
                            'total_amount' => $stars,
                            'invoice_payload' => $invoicePayload,
                            'telegram_payment_charge_id' => $chargeId,
                        ],
                    ],
                ],
            ];
        });
    }

    /**
     * An update shape we do not handle, for asserting that `kind()` says so
     * rather than guessing.
     */
    public function unhandled(): static
    {
        return $this->state(fn (array $attributes): array => [
            'payload' => ['poll_answer' => ['poll_id' => '1']],
        ]);
    }

    /**
     * @param  array<string, mixed>  $from  merged over the default sender
     * @return array<string, mixed>
     */
    private function messagePayload(
        string $text,
        ?int $fromTelegramId = null,
        array $from = [],
        string $chatType = 'private',
    ): array {
        $telegramId = $fromTelegramId ?? fake()->numberBetween(1, 999_999);

        return [
            'message' => [
                'message_id' => fake()->numberBetween(1, 9999),
                'from' => array_replace([
                    'id' => $telegramId,
                    'is_bot' => false,
                    'first_name' => fake()->firstName(),
                ], $from),
                // A private chat's id *is* the user's id; anywhere else it is not,
                // which is precisely why the handler refuses anywhere else.
                'chat' => [
                    'id' => $chatType === 'private' ? $telegramId : -$telegramId,
                    'type' => $chatType,
                ],
                'date' => 1_760_000_000,
                'text' => $text,
            ],
        ];
    }
}
