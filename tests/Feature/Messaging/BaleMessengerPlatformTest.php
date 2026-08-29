<?php

use App\Enums\MessagingPlatform;
use App\Enums\PaymentTransactionStatus;
use App\Messaging\Bale\BaleMessengerPlatform;
use App\Messaging\Contracts\MessengerException;
use App\Services\Telegram\LaravelHttpClient;

beforeEach(function () {
    // Resolving the Api client refuses to exist without a token — nothing here
    // sends, but the client still must resolve, exactly as in the Telegram
    // mirror.
    //
    // No catch-all `Http::fake()` here: it appends and the *first* matching
    // pattern wins, so a catch-all would shadow every per-test stub below.
    // `preventStrayRequests` keeps unsent-methods honest instead.
    config([
        'services.bale.bot_token' => '123456:BALE-TEST-TOKEN',
        'services.bale.bot_username' => '@challengesbot',
    ]);

    Http::preventStrayRequests();

    $this->platform = app(BaleMessengerPlatform::class);
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
        ->and($update->platform)->toBe(MessagingPlatform::Bale)
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
            'id' => '1agg',
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
        // The raw payload keeps the query id, which acknowledgement reads —
        // including the "1" prefix old Bale clients announce themselves with.
        ->and($update->value('callback_query.id'))->toBe('1agg');
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

it('normalizes a voice message, tolerating a missing duration', function () {
    $withDuration = $this->platform->normalizeUpdate([
        'update_id' => 13,
        'message' => [
            'message_id' => 22,
            'from' => ['id' => 444, 'is_bot' => false],
            'chat' => ['id' => 444, 'type' => 'private'],
            'voice' => ['file_id' => 'BAACvoice', 'duration' => 42, 'mime_type' => 'audio/ogg'],
        ],
    ]);

    expect($withDuration)->not->toBeNull()
        ->and($withDuration->voiceFileId)->toBe('BAACvoice')
        ->and($withDuration->voiceDuration)->toBe(42);

    // Bale documents Voice without guaranteeing `duration`; a missing one is
    // null (no cap to enforce), never a coerced zero.
    $without = $this->platform->normalizeUpdate([
        'update_id' => 14,
        'message' => [
            'message_id' => 23,
            'from' => ['id' => 444, 'is_bot' => false],
            'chat' => ['id' => 444, 'type' => 'private'],
            'voice' => ['file_id' => 'BAACvoice2'],
        ],
    ]);

    expect($without)->not->toBeNull()
        ->and($without->voiceDuration)->toBeNull();
});

it('normalizes a forwarded channel post, reading the chat it came from', function () {
    // The one way a creator shows the bot its channel: forward something from
    // it. Same shape as Telegram's, because Bale's API is Telegram-shaped.
    $update = $this->platform->normalizeUpdate([
        'update_id' => 15,
        'message' => [
            'message_id' => 24,
            'from' => ['id' => 555, 'is_bot' => false, 'first_name' => 'Nasim'],
            'chat' => ['id' => 555, 'type' => 'private'],
            'forward_from_chat' => ['id' => -100987, 'type' => 'channel'],
        ],
    ]);

    expect($update)->not->toBeNull()
        ->and($update->forwardedChatId)->toBe(-100987);
});

it('returns null for a shape it does not know', function () {
    expect($this->platform->normalizeUpdate(['update_id' => 16, 'poll_answer' => ['poll_id' => '1']]))->toBeNull();
});

it('sends a message through Bale\'s base URL, serializing the keyboard as Telegram does', function () {
    Http::fake(['*sendMessage*' => Http::response(['ok' => true, 'result' => ['message_id' => 31]])]);

    $sent = $this->platform->sendMessage(9001, 'hello', [[['text' => 'Tap', 'callback_data' => 'x:1']]]);

    expect($sent->messageId)->toBe(31);

    $url = collect(Http::recorded())->map(fn (array $call): string => $call[0]->url())->first();

    expect($url)->toStartWith('https://tapi.bale.ai/bot123456:BALE-TEST-TOKEN/sendMessage');

    $sent = [];
    parse_str(collect(Http::recorded())->first()[0]->body(), $sent);

    expect($sent['reply_markup'] ?? null)->not->toBeEmpty()
        ->and(json_decode($sent['reply_markup'], true)['inline_keyboard'][0][0]['callback_data'] ?? null)->toBe('x:1');
});

it('reads the bot id from getMe once', function () {
    Http::fake(['*getMe*' => Http::response(['ok' => true, 'result' => ['id' => 620_001, 'is_bot' => true]])]);

    expect($this->platform->botId())->toBe(620_001)
        ->and($this->platform->botId())->toBe(620_001);

    // Memoised: one getMe per process, however many verifications ask.
    expect(collect(Http::recorded())
        ->filter(fn ($pair) => str_contains($pair[0]->url(), 'getMe')))
        ->toHaveCount(1);
});

it('maps a ChatMember answer onto the shared snapshot', function () {
    Http::fake(['*getChatMember*' => Http::response([
        'ok' => true,
        'result' => ['status' => 'administrator', 'can_post_messages' => true],
    ])]);

    $member = $this->platform->getChatMember('@challenges', 777);

    expect($member->status)->toBe('administrator')
        ->and($member->canPostMessages)->toBeTrue()
        ->and($member->isMember)->toBeNull();
});

it('refuses to exist without a bot token, loudly', function () {
    config(['services.bale.bot_token' => '']);

    // A fresh instance, because the token is read at client-build time and
    // the singleton may already have memoised a client from beforeEach.
    $platform = new BaleMessengerPlatform(app(LaravelHttpClient::class));

    expect(fn () => $platform->sendMessage(1, 'x'))
        ->toThrow(MessengerException::class);
});

it('refuses an invoice link, because Bale has none to give', function () {
    // The bale-payments skill's trap #1: `createInvoiceLink` on Bale returns a
    // mini-app invoice *id*, not a payable URL, so there is no button to be
    // built from it. Refusing loudly beats handing back something unpayable.
    expect(fn () => $this->platform->createInvoiceLink('t', 'd', 'p', 'IRR', [['label' => 't', 'amount' => 1]]))
        ->toThrow(MessengerException::class, 'no payable invoice link');

    expect(Http::recorded())->toBeEmpty();
});

it('refuses a refund, because Bale documents no refund path', function () {
    expect(fn () => $this->platform->refundPayment('charge', 1))
        ->toThrow(MessengerException::class, 'no refund path');

    expect(Http::recorded())->toBeEmpty();
});

it('sends an invoice into the chat, priced in Rial with the wallet token and no currency parameter', function () {
    config(['services.bale.provider_token' => 'WALLET-TEST-1111111111111111']);

    Http::fake(['*sendInvoice*' => Http::response(['ok' => true, 'result' => ['message_id' => 41]])]);

    $sent = $this->platform->sendInvoice(9001, '50 coins', 'Top up your balance.', 'coins:abc', [
        ['label' => '50 coins', 'amount' => 50_000],
    ]);

    expect($sent->messageId)->toBe(41);

    $calls = Http::recorded()->values();
    expect($calls)->toHaveCount(1)
        ->and($calls[0][0]->url())->toBe('https://tapi.bale.ai/bot123456:BALE-TEST-TOKEN/sendInvoice');

    $sentParams = [];
    parse_str($calls[0][0]->body(), $sentParams);

    expect($sentParams['chat_id'])->toBe('9001')
        ->and($sentParams['payload'])->toBe('coins:abc')

        // The wallet token from @botfather, not the bot token (trap #2).
        ->and($sentParams['provider_token'])->toBe('WALLET-TEST-1111111111111111')

        // Bale Pay prices in Rial and takes no currency parameter at all.
        ->and($sentParams)->not->toHaveKey('currency')
        ->and(json_decode($sentParams['prices'], true, 512, JSON_THROW_ON_ERROR))
        ->toBe([['label' => '50 coins', 'amount' => 50_000]]);
});

it('refuses to send an invoice when the wallet token is not configured', function () {
    expect(fn () => $this->platform->sendInvoice(9001, 't', 'd', 'coins:abc', [['label' => 't', 'amount' => 1]]))
        ->toThrow(MessengerException::class, 'BALE_PROVIDER_TOKEN is not set');

    expect(Http::recorded())->toBeEmpty();
});

it('maps an inquireTransaction answer onto the shared transaction shape', function () {
    Http::fake(['*inquireTransaction*' => Http::response(['ok' => true, 'result' => [
        'id' => 'charge-1',
        'status' => 'paid',
        'userID' => 9001,
        'amount' => 50_000,
    ]])]);

    $transaction = $this->platform->inquireTransaction('charge-1');

    expect($transaction->id)->toBe('charge-1')
        ->and($transaction->status)->toBe(PaymentTransactionStatus::Paid)
        ->and($transaction->amount)->toBe(50_000)
        ->and($transaction->userId)->toBe(9001);
});

it('reads an unknown inquireTransaction status as unknown, never as paid', function () {
    Http::fake(['*inquireTransaction*' => Http::response(['ok' => true, 'result' => [
        'id' => 'charge-2',
        'status' => 'settling-somehow',
    ]])]);

    expect($this->platform->inquireTransaction('charge-2')->status)
        ->toBe(PaymentTransactionStatus::Unknown);
});

it('refuses an inquiry that answered no transaction', function () {
    Http::fake(['*inquireTransaction*' => Http::response(['ok' => true, 'result' => true])]);

    expect(fn () => $this->platform->inquireTransaction('charge-3'))
        ->toThrow(MessengerException::class, 'no transaction');
});
