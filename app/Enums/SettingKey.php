<?php

namespace App\Enums;

use Illuminate\Support\Facades\Config;

/**
 * The registry of every admin-tunable value on the platform.
 *
 * CLAUDE.md is emphatic that no rate or price may be hardcoded. This enum is
 * how that rule is kept honest: a call site names a case, never a string key,
 * and the only numeric literals in the codebase are the documented defaults
 * below. Adding a tunable means adding a case here — the admin panel builds its
 * form from `cases()`, so nothing has to be registered twice.
 *
 * A default is what applies until an admin overrides it; there is no
 * requirement for a `settings` row to exist. See App\Services\Settings.
 */
enum SettingKey: string
{
    /*
    | Economy — coins are the single currency. Credited from invites, Stars
    | purchases, completion rewards and admin adjustments; spent on slots and
    | freezes.
    */
    case InviteCoinReward = 'invite_coin_reward';
    case CreateSlotCoinPrice = 'create_slot_coin_price';
    case JoinSlotCoinPrice = 'join_slot_coin_price';
    case FreezeCoinPrice = 'freeze_coin_price';
    case ChallengeCompletionCoinReward = 'challenge_completion_coin_reward';
    case StarsPackages = 'stars_packages';

    /*
    | Free baseline and per-challenge defaults.
    */
    case FreeCreateSlots = 'free_create_slots';
    case FreeJoinSlots = 'free_join_slots';
    case DefaultChallengeFreezes = 'default_challenge_freezes';

    /*
    | Access gate and token lifetimes. These are seeded from env via config, so
    | a fresh deployment is correct before an admin has touched anything, but
    | remain overridable at runtime without a redeploy.
    */
    case RequiredChannel = 'required_channel';
    case ChannelVerificationTtlMinutes = 'channel_verification_ttl_minutes';
    case MiniAppTokenTtlMinutes = 'miniapp_token_ttl_minutes';
    case InitDataMaxAgeSeconds = 'initdata_max_age_seconds';
    case ConversationTtlMinutes = 'conversation_ttl_minutes';

    /*
    | Reminders.
    */
    case ReminderEndingLeadHours = 'reminder_ending_lead_hours';

    /**
     * The value shape this setting accepts.
     */
    public function type(): SettingType
    {
        return match ($this) {
            self::StarsPackages => SettingType::Json,
            self::RequiredChannel => SettingType::Text,
            default => SettingType::Integer,
        };
    }

    /**
     * The value that applies until an admin overrides it.
     *
     * Env-derived defaults are read through `config()` rather than `env()` so
     * they survive `config:cache`.
     */
    public function default(): mixed
    {
        return match ($this) {
            self::InviteCoinReward => 10,
            self::CreateSlotCoinPrice => 50,
            self::JoinSlotCoinPrice => 25,
            self::FreezeCoinPrice => 15,

            // Flat and platform-funded. No buy-in, no prize pool — Stars are for
            // digital goods only, and wagering would put the bot at risk.
            self::ChallengeCompletionCoinReward => 100,

            /*
            | Stars -> coins. Larger packages carry a bonus, which is why this is
            | a table rather than a single rate. `stars` is the XTR amount charged
            | and `coins` is what gets credited on successful_payment.
            */
            self::StarsPackages => [
                ['stars' => 50, 'coins' => 50],
                ['stars' => 100, 'coins' => 110],
                ['stars' => 250, 'coins' => 290],
                ['stars' => 500, 'coins' => 620],
            ],

            // The free baseline every user gets: one challenge created, one joined.
            self::FreeCreateSlots => 1,
            self::FreeJoinSlots => 1,
            self::DefaultChallengeFreezes => 1,

            self::RequiredChannel => Config::string('services.telegram.required_channel'),

            /*
            | How long a confirmed channel membership is trusted before the gate
            | asks Telegram again. Ten minutes is a deliberate compromise: at ~30
            | Bot API calls a second globally, re-verifying on every privileged
            | action would compete with reminder fan-out, and the cost of the
            | cache is that somebody who leaves the channel keeps access for up to
            | this long. Zero turns the cache off entirely.
            */
            self::ChannelVerificationTtlMinutes => 10,

            self::MiniAppTokenTtlMinutes => 60,
            self::InitDataMaxAgeSeconds => Config::integer('services.telegram.initdata_ttl', 3600),

            /*
            | How long a half-finished bot wizard survives. Long enough that a
            | creator can go and look up a timezone mid-flow, short enough that
            | tomorrow's `/create` starts clean rather than resuming a flow they
            | have forgotten the shape of.
            */
            self::ConversationTtlMinutes => 60,

            /*
            | How long before a period's close the "period ending" nudge fires.
            | Three hours suits a daily rhythm — enough time to act, late enough
            | not to nag. A lead longer than the period itself is clamped to the
            | period's start rather than firing before it opens.
            */
            self::ReminderEndingLeadHours => 3,
        };
    }
}
