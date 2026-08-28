<?php

use App\Enums\MessagingPlatform;
use App\Messaging\Telegram\TelegramMessengerPlatform;

beforeEach(function () {
    // Resolving the platform builds the Api singleton, which refuses to exist
    // without a token — nothing here sends, but the client still must resolve.
    config(['services.telegram.bot_token' => '123456:TEST-TOKEN']);

    $this->platform = app(TelegramMessengerPlatform::class);
});

it('normalizes a text message', function () {
    $update = $this->platform->normalizeUpdate([
        'update_id' => 10,
        'message' => [
            'message_id' => 20,
            'from' => ['id' => 111, 'is_bot' => false, 'first_name' => 'Sahar'],
            'chat' => ['id' => 111, 'type' => 'private'],
            'text' => 'blue anchor 42',
        ],
    ]);

    expect($update)->not->toBeNull()
        ->and($update->platform)->toBe(MessagingPlatform::Telegram)
        ->and($update->platformUserId)->toBe(111)
        ->and($update->chatId)->toBe(111)
        ->and($update->text)->toBe('blue anchor 42')
        ->and($update->callbackData)->toBeNull()
        ->and($update->photoFileId)->toBeNull()
        ->and($update->voiceFileId)->toBeNull()
        ->and($update->forwardedChatId)->toBeNull()
        ->and($update->isPrivateChat())->toBeTrue();
});

it('normalizes a callback query', function () {
    $update = $this->platform->normalizeUpdate([
        'update_id' => 11,
        'callback_query' => [
            'id' => 'agg1',
            'from' => ['id' => 222, 'is_bot' => false],
            'message' => ['message_id' => 20, 'chat' => ['id' => 222, 'type' => 'private']],
            'data' => 'checkin:tap:9',
        ],
    ]);

    expect($update)->not->toBeNull()
        ->and($update->platformUserId)->toBe(222)
        ->and($update->chatId)->toBe(222)
        ->and($update->callbackData)->toBe('checkin:tap:9')
        ->and($update->text)->toBeNull()
        // The raw payload keeps the query id, which acknowledgement reads.
        ->and($update->value('callback_query.id'))->toBe('agg1');
});

it('normalizes a photo, picking the largest size off the ladder', function () {
    $update = $this->platform->normalizeUpdate([
        'update_id' => 12,
        'message' => [
            'message_id' => 21,
            'from' => ['id' => 333, 'is_bot' => false],
            'chat' => ['id' => 333, 'type' => 'private'],
            'photo' => [
                ['file_id' => 'small', 'width' => 160, 'height' => 120],
                ['file_id' => 'largest', 'width' => 1280, 'height' => 960],
                ['file_id' => 'middle', 'width' => 640, 'height' => 480],
            ],
        ],
    ]);

    expect($update)->not->toBeNull()
        ->and($update->photoFileId)->toBe('largest')
        ->and($update->text)->toBeNull();
});

it('normalizes a voice message with its duration', function () {
    $update = $this->platform->normalizeUpdate([
        'update_id' => 13,
        'message' => [
            'message_id' => 22,
            'from' => ['id' => 444, 'is_bot' => false],
            'chat' => ['id' => 444, 'type' => 'private'],
            'voice' => ['file_id' => 'voice1', 'duration' => 37],
        ],
    ]);

    expect($update)->not->toBeNull()
        ->and($update->voiceFileId)->toBe('voice1')
        ->and($update->voiceDuration)->toBe(37)
        ->and($update->photoFileId)->toBeNull();
});

it('normalizes a forwarded message, naming the origin chat', function () {
    $update = $this->platform->normalizeUpdate([
        'update_id' => 14,
        'message' => [
            'message_id' => 23,
            'from' => ['id' => 555, 'is_bot' => false],
            'chat' => ['id' => 555, 'type' => 'private'],
            'forward_from_chat' => ['id' => -100_999, 'type' => 'channel', 'title' => 'Home'],
            'text' => 'forwarded text',
        ],
    ]);

    expect($update)->not->toBeNull()
        ->and($update->forwardedChatId)->toBe(-100_999)
        ->and($update->text)->toBe('forwarded text');
});

it('leaves update kinds it does not describe to the raw payload', function (array $payload) {
    expect($this->platform->normalizeUpdate($payload))->toBeNull();
})->with([
    'pre_checkout_query' => [[
        'update_id' => 15,
        'pre_checkout_query' => ['id' => 'pcq1', 'from' => ['id' => 666], 'currency' => 'XTR'],
    ]],
    'my_chat_member' => [[
        'update_id' => 16,
        'my_chat_member' => ['chat' => ['id' => -100_1], 'from' => ['id' => 666]],
    ]],
]);
