<?php

use App\Actions\Challenges\RegisterChallengeChat;
use App\Actions\Challenges\UpdateChallengeChatSettings;
use App\Actions\Challenges\VerifyChallengeChat;
use App\Enums\ChatLinkVerification;
use App\Enums\ProofType;
use App\Enums\SettingKey;
use App\Enums\TelegramChatType;
use App\Exceptions\ChatLinkRefusedException;
use App\Jobs\Telegram\ProcessTelegramUpdate;
use App\Models\Challenge;
use App\Models\ChallengeChat;
use App\Models\TelegramUpdate;
use App\Models\User;
use App\Services\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/*
 * Linking a channel or group as a challenge's home chat. Two checks gate the
 * link — the bot is admin there, and the *creator* is admin there — and the
 * tests hold them apart, because "re-add the bot" and "re-admin yourself" are
 * different sentences with different fixes. The chat id arrives only from a
 * forwarded message's `forward_from_chat`, so the flow tests go through the
 * whole inbound path: `/chatlink` opens the conversation, a forward answers it.
 *
 * `Http::preventStrayRequests()` rather than a catch-all fake, because
 * `Http::fake()` *appends*: a catch-all in `beforeEach` would win every match
 * and silently shadow the per-test stubs.
 */

beforeEach(function () {
    Http::preventStrayRequests();

    config([
        'services.telegram.bot_token' => '123456:TEST-TOKEN',
        'services.telegram.bot_username' => 'ChallengesBot',
    ]);

    $this->settings = app(Settings::class);
    $this->settings->set(SettingKey::RequiredChannel, '@challenges');

    $this->creator = User::factory()
        ->telegram(777_000_1)
        ->preferring('en')
        ->create(['channel_verified_at' => now()]);

    $this->challenge = Challenge::factory()->create([
        'creator_id' => $this->creator->getKey(),
        'join_token' => 'linktoken1',
        'title' => 'Morning run',
    ]);
});

/**
 * Telegram answers everything a link needs: the bot's identity, three
 * `getChatMember` questions (announcement gate, bot-in-chat, creator-in-chat)
 * and the messages that carry the flow's copy.
 *
 * `$inChat` decides the two link checks at once; when the two checks need
 * different answers, `linkVerdict` below replaces just those two.
 *
 * @param  array<string, mixed>  $chatMemberExtra  merged over the member answer
 */
function telegramServesTheLink(string $inChat = 'administrator', array $chatMemberExtra = []): void
{
    Http::fake([
        '*getMe' => Http::response(['ok' => true, 'result' => ['id' => 999_000_1, 'is_bot' => true, 'first_name' => 'Challenges Bot']]),
        '*getChatMember*' => Http::response([
            'ok' => true,
            'result' => array_replace(
                ['status' => $inChat, 'can_post_messages' => true],
                $chatMemberExtra,
            ),
        ]),
        '*sendMessage*' => Http::response(['ok' => true, 'result' => ['message_id' => 11]]),
    ]);
}

/**
 * Answer the three `getChatMember` questions individually: the gate question
 * by the caller's user id, the two link questions by the constants the
 * verification asks with (bot 999_000_1, creator 777_000_1).
 */
function linkVerdict(string $gate = 'member', string $bot = 'administrator', string $creator = 'administrator', array $botExtra = []): void
{
    Http::fake(function (Request $request) use ($gate, $bot, $creator, $botExtra) {
        $url = $request->url();

        if (str_contains($url, 'getMe')) {
            return Http::response(['ok' => true, 'result' => ['id' => 999_000_1, 'is_bot' => true]]);
        }

        if (str_contains($url, 'sendMessage')) {
            return Http::response(['ok' => true, 'result' => ['message_id' => 11]]);
        }

        if (str_contains($url, 'getChatMember')) {
            $query = [];
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $userId = (int) ($query['user_id'] ?? 0);

            $status = match (true) {
                $userId === 999_000_1 => $bot,
                $userId === 777_000_1 => $creator,
                default => $gate,
            };

            $extra = $userId === 999_000_1 ? array_replace(['can_post_messages' => true], $botExtra) : [];

            return Http::response([
                'ok' => true,
                'result' => array_replace(['status' => $status, 'user' => ['id' => $userId]], $extra),
            ]);
        }

        return Http::response(['ok' => true, 'result' => true]);
    });
}

/**
 * A forwarded message from a channel/group, through the whole inbound path.
 */
function forwardsFromChat(int $telegramId, int $chatId, string $chatType = 'channel', string $title = 'Run Club'): void
{
    $update = TelegramUpdate::factory()
        ->messageFrom(['id' => $telegramId, 'first_name' => 'Sara'], 'a forwarded post')
        ->create();

    $payload = $update->payload;
    $payload['message']['forward_from_chat'] = [
        'id' => $chatId,
        'type' => $chatType,
        'title' => $title,
    ];
    $update->forceFill(['payload' => $payload])->save();

    dispatch_sync(new ProcessTelegramUpdate($update));
}

/**
 * A typed message, through the whole inbound path.
 */
function typesAtTheBot(int $telegramId, string $text): void
{
    $update = TelegramUpdate::factory()
        ->messageFrom(['id' => $telegramId, 'first_name' => 'Sara'], $text)
        ->create();

    dispatch_sync(new ProcessTelegramUpdate($update));
}

/**
 * Every sentence the bot sent, in order.
 *
 * @return list<string>
 */
function sentMessages(): array
{
    return Http::recorded(fn (Request $request): bool => str_contains($request->url(), 'sendMessage'))
        ->map(function (array $call): string {
            $sent = [];
            parse_str((string) $call[0]->body(), $sent);

            return is_string($sent['text'] ?? null) ? $sent['text'] : '';
        })
        ->values()
        ->all();
}

/**
 * The last sentence the bot sent, if it sent one.
 */
function lastSentMessage(): ?string
{
    $messages = sentMessages();

    return $messages === [] ? null : $messages[array_key_last($messages)];
}

describe('the flow, end to end', function () {
    it('links a chat when both admin checks pass', function () {
        telegramServesTheLink();

        typesAtTheBot(777_000_1, '/chatlink j_linktoken1');
        forwardsFromChat(777_000_1, -100_4444, 'channel', 'Run Club');

        $chat = ChallengeChat::query()
            ->where('challenge_id', $this->challenge->getKey())
            ->where('telegram_chat_id', -100_4444)
            ->first();

        expect($chat)->not->toBeNull()
            ->and($chat?->chat_type)->toBe(TelegramChatType::Channel)
            ->and($chat?->title)->toBe('Run Club')
            ->and($chat?->is_active)->toBeTrue()
            ->and($chat?->bot_admin_verified_at)->not->toBeNull()
            ->and($chat?->creator_admin_verified_at)->not->toBeNull();

        expect(lastSentMessage())->toContain('Run Club')->toContain('Morning run');
    });

    it('asks for the forward with a way out of the wait under it', function () {
        linkVerdict();

        typesAtTheBot(777_000_1, '/chatlink j_linktoken1');

        // The creator has to leave Telegram, promote the bot, and come back — and
        // a forwarded message is not a command, so nothing they can type in
        // between ends this. The escape hatch belongs on the message that asks
        // for all of that, which is why `prompt_cancel` travels with a button
        // rather than a sentence naming `/cancel`.
        expect(lastSentMessage())->toContain(botCopy('bot.chatlink.prompt_forward'))
            ->and(lastBotKeyboard())->toBe([[commandButton('en', 'cancel')]]);
    });

    it('tells the creator which admin check failed when the bot is not an admin', function () {
        linkVerdict(bot: 'member');

        typesAtTheBot(777_000_1, '/chatlink j_linktoken1');
        forwardsFromChat(777_000_1, -100_4444);

        expect(ChallengeChat::query()->where('telegram_chat_id', -100_4444)->first()?->is_active)->toBeFalse()
            ->and(lastSentMessage())->toContain('not an administrator');
    });

    it('tells the creator which admin check failed when they are not an admin', function () {
        linkVerdict(creator: 'member');

        typesAtTheBot(777_000_1, '/chatlink j_linktoken1');
        forwardsFromChat(777_000_1, -100_4444);

        expect(ChallengeChat::query()->where('telegram_chat_id', -100_4444)->first()?->is_active)->toBeFalse()
            ->and(lastSentMessage())->toContain('You are not an administrator');
    });

    it('requires posting rights for a channel admin', function () {
        // Administrator of a channel, but barred from posting — the one branch
        // where admin status alone is not enough.
        linkVerdict(bot: 'administrator', botExtra: ['can_post_messages' => false]);

        typesAtTheBot(777_000_1, '/chatlink j_linktoken1');
        forwardsFromChat(777_000_1, -100_4444);

        expect(ChallengeChat::query()->where('telegram_chat_id', -100_4444)->first()?->is_active)->toBeFalse();
    });

    it('refuses a non-creator before any Telegram call is made about the chat', function () {
        // Only the refusal's own `sendMessage` may go out — nothing that asks
        // about the chat. A stray request would fail the test, which is
        // exactly the assertion.
        Http::fake(['*sendMessage*' => Http::response(['ok' => true, 'result' => ['message_id' => 11]])]);

        $other = User::factory()
            ->telegram(777_000_9)
            ->preferring('en')
            ->create(['channel_verified_at' => now()]);

        typesAtTheBot(777_000_9, '/chatlink j_linktoken1');

        // The refusal went out before the forward was even sent for — and the
        // forward afterwards, with no conversation open, gets the ordinary
        // fallback rather than opening anything.
        expect(sentMessages()[0] ?? null)->toContain('Only the creator');

        forwardsFromChat(777_000_9, -100_4444);

        expect(ChallengeChat::query()->count())->toBe(0);

        // Nothing about the chat itself was asked: no `getMe` for the bot's
        // own id, so no `getChatMember` about the linked chat either.
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'getMe'));
    });

    it('re-asks when the message was not forwarded from a chat', function () {
        linkVerdict();

        typesAtTheBot(777_000_1, '/chatlink j_linktoken1');
        // A plain typed message while the flow is open — not a forward.
        typesAtTheBot(777_000_1, 'hello there');

        expect(ChallengeChat::query()->count())->toBe(0)
            ->and(lastSentMessage())->toContain('not a message forwarded')
            // And the conversation is still open, waiting for a real forward.
            ->and($this->creator->conversation()->live()->first()?->state->value)->toBe('awaiting_chat_forward');
    });

    it('refuses a chat that is neither a channel nor a group', function () {
        linkVerdict();

        typesAtTheBot(777_000_1, '/chatlink j_linktoken1');
        forwardsFromChat(777_000_1, -100_4444, 'private');

        expect(ChallengeChat::query()->count())->toBe(0)
            ->and($this->creator->conversation()->live()->first()?->state->value)->toBe('awaiting_chat_forward');
    });
});

describe('re-verification', function () {
    it('deactivates an active chat whose bot admin status was later revoked', function () {
        $chat = ChallengeChat::factory()
            ->channel()
            ->verified()
            ->create(['challenge_id' => $this->challenge->getKey(), 'telegram_chat_id' => -100_4444]);

        // The stamp is older than the TTL, so the check re-runs and finds the
        // bot demoted.
        $chat->forceFill([
            'bot_admin_verified_at' => now()->subDay(),
            'creator_admin_verified_at' => now()->subDay(),
        ])->save();

        linkVerdict(bot: 'member');

        $verifier = app(VerifyChallengeChat::class);
        $outcome = $verifier->ensureFresh($chat->fresh(), 24);

        expect($outcome)->toBe(ChatLinkVerification::BotNotAdmin)
            ->and($chat->fresh()?->is_active)->toBeFalse()
            ->and($chat->fresh()?->bot_admin_verified_at)->toBeNull()
            // The creator is told once, here — not by a retry loop.
            ->and(lastSentMessage())->toContain('no longer an administrator');
    });

    it('spends no Telegram budget on a freshly verified chat', function () {
        $chat = ChallengeChat::factory()->verified()
            ->create(['challenge_id' => $this->challenge->getKey(), 'telegram_chat_id' => -100_4444]);

        // No fake, so any request would fail the test — the assertion.
        $outcome = app(VerifyChallengeChat::class)->ensureFresh($chat, 24);

        expect($outcome)->toBe(ChatLinkVerification::Verified)
            ->and($chat->fresh()?->is_active)->toBeTrue();
    });
});

describe('the settings guard', function () {
    it('refuses proof sharing on a challenge whose proofs are private', function () {
        $chat = ChallengeChat::factory()->verified()
            ->create(['challenge_id' => $this->challenge->getKey(), 'telegram_chat_id' => -100_4444]);

        expect(fn () => app(UpdateChallengeChatSettings::class)->handle($this->creator, $chat, [
            'share_proof_media' => true,
        ]))->toThrow(ChatLinkRefusedException::class);

        expect($chat->fresh()?->share_proof_media)->toBeFalse();
    });

    it('allows proof sharing when the challenge publishes its proofs', function () {
        $challenge = Challenge::factory()
            ->provenBy(ProofType::ImageApproval, public: true)
            ->create([
                'creator_id' => $this->creator->getKey(),
                'join_token' => 'linktoken2',
            ]);

        $chat = ChallengeChat::factory()->verified()
            ->create(['challenge_id' => $challenge->getKey(), 'telegram_chat_id' => -100_5555]);

        app(UpdateChallengeChatSettings::class)->handle($this->creator, $chat, [
            'share_proof_media' => true,
            'post_checkin_announcements' => true,
        ]);

        expect($chat->fresh()?->share_proof_media)->toBeTrue()
            ->and($chat->fresh()?->post_checkin_announcements)->toBeTrue();
    });

    it('refuses a settings change from somebody who did not create the challenge', function () {
        $chat = ChallengeChat::factory()->verified()
            ->create(['challenge_id' => $this->challenge->getKey(), 'telegram_chat_id' => -100_4444]);

        $other = User::factory()->telegram(777_000_9)->create();

        expect(fn () => app(UpdateChallengeChatSettings::class)->handle($other, $chat, [
            'post_daily_leaderboard' => true,
        ]))->toThrow(ChatLinkRefusedException::class);
    });
});

describe('registration', function () {
    it('refuses a non-creator at the action layer', function () {
        $other = User::factory()->telegram(777_000_9)->create();

        expect(fn () => app(RegisterChallengeChat::class)->handle(
            $other,
            $this->challenge,
            -100_4444,
            TelegramChatType::Channel,
            'Run Club',
        ))->toThrow(ChatLinkRefusedException::class);
    });

    it('reuses the row when the same chat is linked again, stripped back to unverified', function () {
        $creator = $this->creator;

        $first = app(RegisterChallengeChat::class)->handle($creator, $this->challenge, -100_4444, TelegramChatType::Channel, 'Run Club');
        $first->forceFill(['is_active' => true, 'bot_admin_verified_at' => now(), 'creator_admin_verified_at' => now()])->save();

        $again = app(RegisterChallengeChat::class)->handle($creator, $this->challenge, -100_4444, TelegramChatType::Channel, 'Run Club Renamed');

        expect($again->getKey())->toBe($first->getKey())
            ->and($again->is_active)->toBeFalse()
            ->and($again->bot_admin_verified_at)->toBeNull()
            ->and($again->title)->toBe('Run Club Renamed')
            ->and(ChallengeChat::query()->count())->toBe(1);
    });
});
