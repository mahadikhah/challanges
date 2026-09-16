<?php

namespace App\Services\Telegram\Wizards;

use App\Actions\Ai\ScreenApprovalCriteria;
use App\Actions\Ai\SuggestApprovalCriteria;
use App\Actions\Challenges\CreateChallenge;
use App\Actions\Challenges\ValidateChallengeStepDesign;
use App\Actions\Entitlements\ConsumeEntitlement;
use App\Actions\Telegram\VerifyChannelMembership;
use App\Enums\ApprovalCriteriaVerdict;
use App\Enums\ApprovalMode;
use App\Enums\ChallengeVisibility;
use App\Enums\ConversationState;
use App\Enums\EntitlementType;
use App\Enums\FlowType;
use App\Enums\PeriodType;
use App\Enums\ProofType;
use App\Enums\ScoringType;
use App\Enums\SettingKey;
use App\Enums\StepInputType;
use App\Exceptions\NoEntitlementAvailableException;
use App\Models\BotConversation;
use App\Models\Challenge;
use App\Models\User;
use App\Services\Ai\AiApprovalGate;
use App\Services\Localization;
use App\Services\Settings;
use App\Services\Telegram\BotButtons;
use App\Services\Telegram\BotCallback;
use App\Services\Telegram\BotMessenger;
use App\Services\Telegram\ChannelGatePrompt;
use App\Services\Telegram\CheckInInstruction;
use App\Services\Telegram\CompactDuration;
use App\Services\Telegram\PeriodUnit;
use App\Services\Telegram\ReportedValue;
use App\Services\Telegram\SlotRefusal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use LogicException;

/**
 * The create-challenge wizard: ten questions, one challenge.
 *
 * The Telegram SDK has no conversation support, so the flow's position lives in a
 * `BotConversation` row — state plus the answers gathered so far plus an expiry.
 * Everything about the design follows from that row being the only memory between
 * two unrelated queue jobs.
 *
 * **The order of the questions is owned here, not by `ConversationState`.** Two
 * deliberate departures from the enum's declaration order. Timezone is asked
 * *before* the start date, because "starting on the 3rd" is not an instant until
 * the zone is known and asking afterwards would mean re-interpreting an answer
 * already given. The custom day count is asked only for `period_type = custom`,
 * which is why the sequence is a function of the draft rather than a list.
 *
 * **A tapped button carries the step it was asked for.** Inline keyboards outlive
 * the message they were sent with: nothing stops a user scrolling up and tapping
 * "Daily" again three questions later. Comparing the button's step against the
 * conversation's actual state turns that from a corruption into a no-op that
 * re-asks the current question — which is also what a user who taps a button from
 * a finished wizard needs to see.
 *
 * **The gate is checked twice, at `/create` and again at confirmation.** Not
 * belt-and-braces: the questions take minutes, and CLAUDE.md requires
 * authorization be re-validated at the point of the privileged act rather than
 * once at the start of a flow.
 */
class CreateChallengeWizard
{
    /**
     * The callback action word for every button this wizard sends.
     *
     * Two letters because `callback_data` is 64 bytes and a step name already
     * costs up to 27 of them.
     */
    public const string ACTION = 'wz';

    /**
     * Button values that are answers to no particular question.
     */
    public const string SKIP = 'skip';

    public const string CONFIRM = 'go';

    public const string CANCEL = 'no';

    public const string TODAY = 'today';

    public const string TOMORROW = 'tomorrow';

    /**
     * The step-loop's two answers: gather another step, or hand the design in.
     */
    public const string ADD_STEP = 'add';

    public const string DONE_STEPS = 'done';

    /**
     * The partial opt-in's two answers: a below-target report keeps the
     * streak at partial score, or does not.
     */
    public const string PARTIAL_ON = 'on';

    public const string PARTIAL_OFF = 'off';

    /**
     * The bounds a step answer is held to. These are legibility floors, not
     * economy: they keep one answer from being unusable by the design (a wait
     * longer than a day, a voice cap longer than an hour), while the rule that
     * actually matters — the waits summing to no more than one period — is the
     * design validator's, checked when the creator says done.
     */
    private const int STEP_WAIT_MAX = 86_400;

    private const int VOICE_LIMIT_MAX = 3_600;

    private const int STEP_LABEL_MAX = 120;

    /**
     * The proof types and step inputs this wizard can build a working
     * challenge for.
     *
     * A smaller list than the enums' full case lists, deliberately. The enum
     * grows first (Phase 14 adds voice/video proof and video steps at the
     * schema level); the wizard catches up when the bot can actually *collect*
     * the new kind — a recording-proof challenge whose check-in conversation
     * does not exist yet is a challenge no participant can satisfy, and
     * offering it here would let a creator build one by accident. The wizard
     * also asks for the media caps a recording proof requires, and that
     * question arrives with the capture flow.
     *
     * @var list<ProofType>
     */
    private const array PROOF_TYPES = [
        ProofType::Button,
        ProofType::TextAutogen,
        ProofType::ImageApproval,
    ];

    /** @var list<StepInputType> */
    private const array STEP_INPUT_TYPES = [
        StepInputType::Button,
        StepInputType::Image,
        StepInputType::Voice,
    ];

    /**
     * The zones offered as buttons.
     *
     * A curated list rather than all 400-odd IANA identifiers, which no inline
     * keyboard can show: a creator picks a zone once, and these cover the
     * platform's languages plus the common diaspora. `CreateChallenge` accepts any
     * valid identifier, so the Mini App can offer a full picker without changing
     * anything here.
     *
     * @var list<string>
     */
    private const array TIMEZONES = [
        'Asia/Tehran',
        'UTC',
        'Europe/London',
        'Europe/Berlin',
        'Europe/Istanbul',
        'Asia/Dubai',
        'America/New_York',
        'America/Los_Angeles',
    ];

    public function __construct(
        private readonly VerifyChannelMembership $gate,
        private readonly ChannelGatePrompt $gatePrompt,
        private readonly ConsumeEntitlement $entitlements,
        private readonly CreateChallenge $createChallenge,
        private readonly ValidateChallengeStepDesign $stepDesign,
        private readonly SuggestApprovalCriteria $suggestCriteria,
        private readonly ScreenApprovalCriteria $screenCriteria,
        private readonly BotMessenger $messenger,
        private readonly BotButtons $buttons,
        private readonly Settings $settings,
        private readonly SlotRefusal $refusal,
        private readonly AiApprovalGate $aiApprovalGate,
        private readonly ReportedValue $numbers,
        private readonly CheckInInstruction $instructions,
        private readonly PeriodUnit $units,
    ) {}

    /**
     * Open a fresh flow, or refuse to.
     *
     * Refuses before the first question rather than after the tenth: a creator
     * with no slot left should not answer ten questions to be told so. The
     * authoritative check is still the one inside `CreateChallenge`'s
     * transaction — this one is advisory, because a slot can be spent elsewhere
     * while the wizard is open.
     */
    public function begin(User $user): void
    {
        if (! $this->gate->ensure($user)) {
            $this->gatePrompt->send($user);

            return;
        }

        if ($this->entitlements->available($user, EntitlementType::CreateSlot) < 1) {
            $this->refuseForNoSlot($user);

            return;
        }

        $restarting = $user->conversation()->live()->exists();

        $conversation = BotConversation::query()->updateOrCreate(
            ['user_id' => $user->getKey()],
            [
                'state' => ConversationState::AwaitingChallengeTitle,
                'payload' => null,
                'expires_at' => now()->addMinutes($this->settings->integer(SettingKey::ConversationTtlMinutes)),
            ],
        );

        $this->ask($user, $conversation, $restarting
            ? [$this->messenger->line($user, 'bot.wizard.restarted')]
            : [$this->messenger->line($user, 'bot.wizard.opening')]);
    }

    /**
     * Drop a flow at the user's request.
     *
     * @return bool whether there was one to drop
     */
    public function abandon(User $user): bool
    {
        // Any conversation, live or lapsed: leaving an expired row behind would
        // make the *next* `/create` report that it is restarting something.
        return $user->conversation()->delete() > 0;
    }

    /**
     * Take a typed answer.
     *
     * A message with no text — a photo, a sticker — is not an error and not an
     * answer: the question is asked again. Same for text arriving at a step that
     * wants a button, which is what a user does when they miss the keyboard.
     */
    public function receiveText(User $user, BotConversation $conversation, ?string $text): void
    {
        $state = $this->assertOwnState($conversation);

        if ($text === null || ! $state->expectsText()) {
            $this->ask($user, $conversation, [$this->say($user, $conversation, "bot.wizard.{$state->value}.expected")]);

            return;
        }

        // A typed criteria is screened before it is stored, and the verdict
        // decides what the flow carries forward — its own branch for the same
        // reason the step loop has one: two different destinations.
        if ($state === ConversationState::AwaitingApprovalCriteria) {
            $this->receiveTypedCriteria($user, $conversation, $text);

            return;
        }

        $answers = $this->parseText($state, $text, ChallengeDraft::of($conversation));
        if ($answers === null) {
            $this->ask($user, $conversation, [$this->say($user, $conversation, "bot.wizard.{$state->value}.error")]);

            return;
        }

        $this->advance($user, $conversation, $state, $answers);
    }

    /**
     * Take a tapped answer.
     */
    public function receiveChoice(User $user, BotConversation $conversation, BotCallback $callback): void
    {
        $this->assertOwnState($conversation);

        $asked = ConversationState::tryFrom((string) $callback->argument(0));
        $value = $callback->argument(1);

        if ($asked === null || $value === null) {
            $this->ask($user, $conversation, [$this->messenger->line($user, 'bot.fallback.stale_button')]);

            return;
        }

        if ($asked !== $conversation->state) {
            // A button from further up the chat. Not corruption and not worth an
            // error — show them where the flow actually is.
            $this->ask($user, $conversation, [$this->messenger->line($user, 'bot.wizard.stale_step')]);

            return;
        }

        if ($value === self::CANCEL) {
            $this->abandon($user);
            $this->messenger->send($user, $this->messenger->line($user, 'bot.wizard.cancelled'));

            return;
        }

        if ($asked === ConversationState::AwaitingApprovalMode) {
            // Choosing AI review generates the suggestion right away, so the
            // next thing the creator sees is either the suggestion to confirm
            // or the write-your-own question — never a dead "ok".
            if (($mode = ApprovalMode::tryFrom($value)) !== null) {
                $this->chooseApprovalMode($user, $conversation, $mode);
            } else {
                $this->ask($user, $conversation, [$this->say($user, $conversation, 'bot.wizard.awaiting_approval_mode.error')]);
            }

            return;
        }

        if ($asked === ConversationState::AwaitingApprovalCriteriaConfirm) {
            if ($value === self::CONFIRM || $value === self::SKIP) {
                $this->answerCriteriaChoice($user, $conversation, $value);

                return;
            }

            $this->ask($user, $conversation, [$this->messenger->line($user, 'bot.fallback.stale_button')]);

            return;
        }

        if ($asked === ConversationState::AwaitingStepLoop) {            // The loop's two answers each take a different next step, so neither
            // can go through the one-question-one-successor `advance()`.
            if ($value === self::ADD_STEP) {
                $conversation->advanceTo(ConversationState::AwaitingStepInputType, [ChallengeDraft::PENDING_STEP => null])->save();

                $this->ask($user, $conversation);

                return;
            }

            if ($value === self::DONE_STEPS) {
                $this->handInSteps($user, $conversation);

                return;
            }
        }

        if ($asked === ConversationState::AwaitingCreateConfirmation) {
            if ($value === self::CONFIRM) {
                $this->finish($user, $conversation);

                return;
            }

            $this->ask($user, $conversation, [$this->messenger->line($user, 'bot.fallback.stale_button')]);

            return;
        }

        $answers = $this->parseChoice($asked, $value, ChallengeDraft::of($conversation));

        if ($answers === null) {
            $this->ask($user, $conversation, [$this->say($user, $conversation, "bot.wizard.{$asked->value}.error")]);

            return;
        }

        $this->advance($user, $conversation, $asked, $answers);
    }

    /**
     * Record an accepted answer and move on.
     *
     * @param  array<string, mixed>  $answers
     */
    private function advance(
        User $user,
        BotConversation $conversation,
        ConversationState $from,
        array $answers,
    ): void {
        // The answer is merged before the next step is chosen, because the one
        // branch in the flow is a function of the answer just given.
        $conversation->advanceTo($from, $answers);

        $conversation->advanceTo($this->nextAfter($from, ChallengeDraft::of($conversation)))->save();

        $this->ask($user, $conversation);
    }

    /**
     * The step after this one, given what has been answered.
     *
     * Reading it top to bottom is reading the flow, which is the point of keeping
     * it in one place rather than as a `->next()` on each step.
     */
    private function nextAfter(ConversationState $state, ChallengeDraft $draft): ConversationState
    {
        return match ($state) {
            ConversationState::AwaitingChallengeTitle => ConversationState::AwaitingChallengeDescription,
            ConversationState::AwaitingChallengeDescription => ConversationState::AwaitingPeriodType,

            // The only branch in the flow: a day count is meaningless for the
            // five fixed period types.
            ConversationState::AwaitingPeriodType => $draft->periodType()?->requiresCustomDays() === true
                ? ConversationState::AwaitingCustomPeriodDays
                : ConversationState::AwaitingTimezone,

            ConversationState::AwaitingCustomPeriodDays => ConversationState::AwaitingTimezone,
            ConversationState::AwaitingTimezone => ConversationState::AwaitingStartDate,
            ConversationState::AwaitingStartDate => ConversationState::AwaitingTotalPeriods,

            // The scoring fork: only a quantity challenge is asked what it
            // measures — binary skips the whole block. The four questions are
            // asked in this order because each answers what the next one is
            // *about* ("how many :unit?", "worth :points of those").
            ConversationState::AwaitingTotalPeriods => ConversationState::AwaitingScoringType,
            ConversationState::AwaitingScoringType => $draft->scoringType()->isQuantity()
                ? ConversationState::AwaitingScoringTarget
                : ConversationState::AwaitingProofType,
            ConversationState::AwaitingScoringTarget => ConversationState::AwaitingScoringUnit,
            ConversationState::AwaitingScoringUnit => ConversationState::AwaitingScoringBasePoints,
            ConversationState::AwaitingScoringBasePoints => ConversationState::AwaitingScoringPartial,
            ConversationState::AwaitingScoringPartial => ConversationState::AwaitingProofType,

            // The third branch: only a media-proof challenge is asked who
            // reviews it — and only when this deployment's admin allows AI
            // review for that media type; otherwise manual is the only mode
            // and the question would have one button. AI review then needs
            // criteria, gathered on its own two-step path (`criteriaFlow`
            // owns the transition in).
            ConversationState::AwaitingProofType => $this->asksApprovalMode($draft)
                ? ConversationState::AwaitingApprovalMode
                : ConversationState::AwaitingVisibility,

            ConversationState::AwaitingApprovalMode => $draft->approvalMode() === ApprovalMode::Ai
                ? ConversationState::AwaitingApprovalCriteria
                : ConversationState::AwaitingVisibility,
            ConversationState::AwaitingApprovalCriteria,
            ConversationState::AwaitingApprovalCriteriaConfirm => ConversationState::AwaitingVisibility,
            ConversationState::AwaitingVisibility => ConversationState::AwaitingFlowType,

            // The second branch: a timed session opens the step loop, which
            // closes on its own terms (see `handInSteps`), never by advancing.
            ConversationState::AwaitingFlowType => $draft->flowType() === FlowType::TimedSession
                ? ConversationState::AwaitingStepLoop
                : ConversationState::AwaitingCreateConfirmation,

            // The step loop's questions. The wait's successor depends on what
            // the step being gathered wants; a voice step needs its cap first.
            ConversationState::AwaitingStepInputType => ConversationState::AwaitingStepWait,
            ConversationState::AwaitingStepWait => ($draft->pendingStep()['input_type'] ?? null) === StepInputType::Voice->value
                ? ConversationState::AwaitingStepVoiceLimit
                : ConversationState::AwaitingStepLabel,
            ConversationState::AwaitingStepVoiceLimit => ConversationState::AwaitingStepLabel,
            ConversationState::AwaitingStepLabel => ConversationState::AwaitingStepLoop,

            // Confirmation and the loop's exit are answered by their own
            // branches, never by advancing, and the check-in and chat-link
            // steps belong to other flows entirely. Reaching any of them is a
            // wiring bug rather than bad input.
            ConversationState::AwaitingStepLoop,
            ConversationState::AwaitingCreateConfirmation,
            ConversationState::AwaitingCheckInText,
            ConversationState::AwaitingCheckInPhoto,
            ConversationState::AwaitingCheckInVoice,
            ConversationState::AwaitingCheckInVideo,
            ConversationState::AwaitingCheckInValue,
            ConversationState::AwaitingChatForward => throw new LogicException(
                "The create-challenge wizard has no step after {$state->value}.",
            ),
        };
    }

    /**
     * Whether the proof type just chosen earns the who-reviews question at
     * all: a media proof the admin allows AI review for does; everything else
     * goes straight to visibility with manual as the only mode.
     */
    private function asksApprovalMode(ChallengeDraft $draft): bool
    {
        $proofType = $draft->proofType();

        return $proofType !== null
            && $proofType->isMediaApproval()
            && $this->aiApprovalGate->allows($proofType);
    }

    /**
     * Ask the current question.
     *
     * @param  list<string|null>  $preamble  said before the question — an error, a
     *                                       nudge, or nothing
     */
    private function ask(User $user, BotConversation $conversation, array $preamble = []): void
    {
        $state = $conversation->state;
        $draft = ChallengeDraft::of($conversation);

        $this->messenger->paragraphs(
            $user,
            [
                ...$preamble,
                ...($state === ConversationState::AwaitingCreateConfirmation ? $this->summary($user, $draft) : []),
                $this->messenger->line($user, "bot.wizard.{$state->value}.prompt", $this->promptReplacements($draft)),
            ],
            $this->keyboard($user, $state, $draft),
        );
    }

    /**
     * A line of this flow's copy, with the flow's placeholders filled in.
     *
     * Every step's `error` and `expected` line quotes the same bound its `prompt`
     * does — "between 3 and :title_max characters" is the whole point of the
     * message — so they resolve through the same replacements rather than each
     * caller remembering to pass them.
     */
    private function say(User $user, BotConversation $conversation, string $key): string
    {
        return $this->messenger->line(
            $user,
            $key,
            $this->promptReplacements(ChallengeDraft::of($conversation)),
        );
    }

    /**
     * Values a prompt may interpolate. Absent answers read as an em dash rather
     * than as `:timezone`, because a prompt is shown before its own answer exists.
     *
     * @return array<string, string|int|float>
     */
    private function promptReplacements(ChallengeDraft $draft): array
    {
        return [
            'timezone' => $draft->timezone() ?? '—',
            'criteria' => $draft->approvalCriteria() ?? '—',
            'title_max' => CreateChallenge::limits()['title_max'],
            'description_max' => CreateChallenge::limits()['description_max'],
            'total_periods_max' => CreateChallenge::limits()['total_periods_max'],
            'custom_period_days_max' => CreateChallenge::limits()['custom_period_days_max'],
            'criteria_max' => CreateChallenge::limits()['approval_criteria_max'],
            'unit_max' => CreateChallenge::limits()['unit_label_max'],
            'wait_max' => self::STEP_WAIT_MAX,
            'voice_max' => self::VOICE_LIMIT_MAX,
            'label_max' => self::STEP_LABEL_MAX,
        ];
    }

    /**
     * The buttons for a step, or none when it only takes typing.
     *
     * @return list<list<array<string, string>>>|null
     */
    private function keyboard(User $user, ConversationState $state, ChallengeDraft $draft): ?array
    {
        $options = match ($state) {
            // The opening line promises ten questions, so the way out of them
            // rides on the first one. Every later question still answers to
            // `/cancel` — typed, or tapped in the command menu — and carrying a
            // Cancel row on all ten would be a permanent second exit next to the
            // confirmation step's own.
            ConversationState::AwaitingChallengeTitle => [
                self::CANCEL => $this->messenger->line($user, 'bot.wizard.cancel_button'),
            ],
            ConversationState::AwaitingChallengeDescription => [
                self::SKIP => $this->messenger->line($user, 'bot.wizard.skip_button'),
            ],
            ConversationState::AwaitingPeriodType => $this->enumOptions($user, PeriodType::cases()),
            ConversationState::AwaitingTimezone => array_combine(self::TIMEZONES, self::TIMEZONES),
            ConversationState::AwaitingStartDate => [
                self::TODAY => $this->messenger->line($user, 'bot.wizard.today_button'),
                self::TOMORROW => $this->messenger->line($user, 'bot.wizard.tomorrow_button'),
            ],
            ConversationState::AwaitingProofType => $this->enumOptions($user, self::PROOF_TYPES),
            ConversationState::AwaitingScoringType => $this->enumOptions($user, ScoringType::cases()),
            ConversationState::AwaitingScoringPartial => [
                self::PARTIAL_ON => $this->messenger->line($user, 'bot.wizard.partial_on_button'),
                self::PARTIAL_OFF => $this->messenger->line($user, 'bot.wizard.partial_off_button'),
            ],
            ConversationState::AwaitingApprovalMode => $this->enumOptions($user, ApprovalMode::cases()),
            ConversationState::AwaitingApprovalCriteriaConfirm => [
                self::CONFIRM => $this->messenger->line($user, 'bot.wizard.criteria_accept_button'),
                self::SKIP => $this->messenger->line($user, 'bot.wizard.criteria_edit_button'),
            ],
            ConversationState::AwaitingVisibility => $this->enumOptions($user, ChallengeVisibility::cases()),
            ConversationState::AwaitingFlowType => $this->enumOptions($user, FlowType::cases()),
            ConversationState::AwaitingStepLoop => [
                self::ADD_STEP => $this->messenger->line($user, 'bot.wizard.add_step_button'),
                self::DONE_STEPS => $this->messenger->line($user, 'bot.wizard.done_steps_button'),
            ],
            ConversationState::AwaitingStepInputType => $this->enumOptions($user, self::STEP_INPUT_TYPES),
            ConversationState::AwaitingStepLabel => [
                self::SKIP => $this->messenger->line($user, 'bot.wizard.skip_button'),
            ],
            ConversationState::AwaitingCreateConfirmation => [
                self::CONFIRM => $this->messenger->line($user, 'bot.wizard.create_button'),
                self::CANCEL => $this->messenger->line($user, 'bot.wizard.cancel_button'),
            ],
            default => [],
        };

        if ($options === []) {
            return null;
        }

        // One per row for the long labels, two across otherwise. Proof types and
        // the partial opt-in are sentences; period types and timezones are words.
        $perRow = in_array($state, [
            ConversationState::AwaitingProofType,
            ConversationState::AwaitingApprovalMode,
            ConversationState::AwaitingVisibility,
            ConversationState::AwaitingScoringPartial,
        ], true) ? 1 : 2;

        return $this->rows($state, $options, $perRow);
    }

    /**
     * Enum cases as `value => label`, translated for this recipient.
     *
     * `label()` is not usable here: it resolves in the ambient locale, which in a
     * queue worker is whoever was processed last.
     *
     * @param  list<PeriodType|ProofType|ChallengeVisibility|FlowType|StepInputType|ApprovalMode|ScoringType>  $cases
     * @return array<string, string>
     */
    private function enumOptions(User $user, array $cases): array
    {
        $options = [];

        foreach ($cases as $case) {
            $options[$case->value] = $this->messenger->line($user, $case->translationKey());
        }

        return $options;
    }

    /**
     * Lay options out as inline keyboard rows.
     *
     * @param  array<string, string>  $options  callback value => button label
     * @param  positive-int  $perRow
     * @return list<list<array<string, string>>>
     */
    private function rows(ConversationState $state, array $options, int $perRow): array
    {
        $rows = [];

        foreach (array_chunk(array_keys($options), $perRow) as $chunk) {
            $rows[] = array_map(fn (string $value): array => [
                'text' => $options[$value],

                // The step travels with the button so a tap can be checked against
                // where the flow actually is.
                'callback_data' => BotCallback::encode(self::ACTION, $state->value, $value),
            ], $chunk);
        }

        return $rows;
    }

    /**
     * The draft, read back before it becomes a challenge.
     *
     * @return list<string>
     */
    private function summary(User $user, ChallengeDraft $draft): array
    {
        $periodType = $draft->periodType();
        $proofType = $draft->proofType();
        $visibility = $draft->visibility();
        $steps = $draft->steps();

        $flowLine = $draft->flowType() === FlowType::TimedSession
            ? $this->messenger->line($user, 'bot.wizard.summary_steps', [
                'steps' => count($steps),
                'minimum' => CompactDuration::format($this->stepDesign->minimumSeconds($steps)),
                'period' => CompactDuration::format($this->stepDesign->periodSeconds(
                    $periodType ?? PeriodType::Daily,
                    $draft->customPeriodDays(),
                    $draft->startsAt(),
                    (string) $draft->timezone(),
                )),
            ])
            : $this->messenger->line($user, $draft->flowType()->translationKey());

        // Only AI review is worth a line of its own: the criteria is what the
        // participants' proofs will be judged against, and the confirmation is
        // the last place a creator can catch a suggestion they meant to edit.
        $approvalLine = $draft->approvalMode() === ApprovalMode::Ai && $draft->approvalCriteria() !== null
            ? [$this->messenger->line($user, 'bot.wizard.summary_approval', [
                'criteria' => (string) $draft->approvalCriteria(),
            ])]
            : [];

        // Same for the scoring design: the target, unit and points are what
        // every participant's period is judged against, and this is the last
        // place a creator can catch a target they meant to change.
        $scoringLine = $draft->scoringType()->isQuantity()
            ? [$this->messenger->line($user, 'bot.wizard.summary_scoring', [
                'target' => (string) $draft->targetValue(),
                'unit' => (string) $draft->unitLabel(),
                'points' => (string) $draft->basePoints(),
                'partial' => $this->messenger->line($user, $draft->quantityPartialCountsAsDone()
                    ? 'bot.wizard.partial_on_button'
                    : 'bot.wizard.partial_off_button'),
            ])]
            : [];

        return [$this->messenger->line($user, 'bot.wizard.summary', [
            'title' => (string) $draft->title(),
            'description' => $draft->description() ?? $this->messenger->line($user, 'bot.wizard.no_description'),
            'period' => $periodType === null ? '—' : $this->messenger->line($user, $periodType->translationKey()),
            'custom_days' => $draft->customPeriodDays() ?? '—',
            'start' => (string) $draft->startDate(),
            'timezone' => (string) $draft->timezone(),
            'periods' => $draft->totalPeriods() ?? '—',
            'proof' => $proofType === null ? '—' : $this->messenger->line($user, $proofType->translationKey()),
            'visibility' => $visibility === null ? '—' : $this->messenger->line($user, $visibility->translationKey()),
            'flow' => $flowLine,
            'freezes' => $this->settings->integer(SettingKey::DefaultChallengeFreezes),
        ]), ...$approvalLine, ...$scoringLine];
    }

    /**
     * Read a typed answer, or null if it cannot be read.
     *
     * @return array<string, mixed>|null answers to merge into the draft
     */
    private function parseText(ConversationState $state, string $text, ChallengeDraft $draft): ?array
    {
        $text = trim($text);
        $limits = CreateChallenge::limits();

        return match ($state) {
            ConversationState::AwaitingChallengeTitle => mb_strlen($text) >= 3
                && mb_strlen($text) <= $limits['title_max']
                    ? [ChallengeDraft::TITLE => $text]
                    : null,

            ConversationState::AwaitingChallengeDescription => mb_strlen($text) <= $limits['description_max']
                ? [ChallengeDraft::DESCRIPTION => $text]
                : null,

            ConversationState::AwaitingCustomPeriodDays => ($days = $this->positiveInteger($text, $limits['custom_period_days_max'])) === null
                ? null
                : [ChallengeDraft::CUSTOM_PERIOD_DAYS => $days],

            ConversationState::AwaitingTotalPeriods => ($periods = $this->positiveInteger($text, $limits['total_periods_max'])) === null
                ? null
                : [ChallengeDraft::TOTAL_PERIODS => $periods],

            ConversationState::AwaitingScoringTarget => ($target = $this->numbers->normalise($text)) === null || (float) $target <= 0
                ? null
                : [ChallengeDraft::TARGET_VALUE => $target],

            ConversationState::AwaitingScoringUnit => mb_strlen($text) >= 1
                && mb_strlen($text) <= $limits['unit_label_max']
                    ? [ChallengeDraft::UNIT_LABEL => $text]
                    : null,

            ConversationState::AwaitingScoringBasePoints => ($points = $this->positiveInteger($text, PHP_INT_MAX)) === null
                ? null
                : [ChallengeDraft::BASE_POINTS => (string) $points],

            ConversationState::AwaitingStartDate => ($date = $this->readDate($text, $draft)) === null
                ? null
                : [ChallengeDraft::START_DATE => $date],

            ConversationState::AwaitingStepWait => ($wait = $this->boundedInteger($text, 0, self::STEP_WAIT_MAX)) === null
                ? null
                : [ChallengeDraft::PENDING_STEP => $this->withPending($draft, ['min_wait_seconds' => $wait])],

            ConversationState::AwaitingStepVoiceLimit => ($limit = $this->boundedInteger($text, 1, self::VOICE_LIMIT_MAX)) === null
                ? null
                : [ChallengeDraft::PENDING_STEP => $this->withPending($draft, ['voice_max_seconds' => $limit])],

            ConversationState::AwaitingStepLabel => mb_strlen($text) <= self::STEP_LABEL_MAX
                ? $this->completePendingStep($draft, $text)
                : null,

            default => null,
        };
    }

    /**
     * Read a tapped answer, or null if it is not one of the offered values.
     *
     * Every value is checked against what was actually offered rather than
     * trusted: `callback_data` is a string a client sent us.
     *
     * @return array<string, mixed>|null
     */
    private function parseChoice(ConversationState $state, string $value, ChallengeDraft $draft): ?array
    {
        return match ($state) {
            ConversationState::AwaitingChallengeDescription => $value === self::SKIP
                ? [ChallengeDraft::DESCRIPTION => null]
                : null,

            ConversationState::AwaitingPeriodType => ($type = PeriodType::tryFrom($value)) === null
                ? null
                : [ChallengeDraft::PERIOD_TYPE => $type->value],

            ConversationState::AwaitingTimezone => in_array($value, self::TIMEZONES, true)
                ? [ChallengeDraft::TIMEZONE => $value]
                : null,

            ConversationState::AwaitingStartDate => match ($value) {
                self::TODAY => [ChallengeDraft::START_DATE => $this->localToday($draft)->toDateString()],
                self::TOMORROW => [ChallengeDraft::START_DATE => $this->localToday($draft)->addDay()->toDateString()],
                default => null,
            },

            ConversationState::AwaitingProofType => ($proof = ProofType::tryFrom($value)) !== null
                && in_array($proof, self::PROOF_TYPES, true)
                ? [ChallengeDraft::PROOF_TYPE => $proof->value]
                : null,

            ConversationState::AwaitingScoringType => ($scoring = ScoringType::tryFrom($value)) !== null
                ? [ChallengeDraft::SCORING_TYPE => $scoring->value]
                : null,

            ConversationState::AwaitingScoringPartial => match ($value) {
                self::PARTIAL_ON => [ChallengeDraft::QUANTITY_PARTIAL => '1'],
                self::PARTIAL_OFF => [ChallengeDraft::QUANTITY_PARTIAL => '0'],
                default => null,
            },

            // `parseText`, not here: a typed criteria is the normal path and
            // the confirm step's two buttons are handled in `receiveChoice`.
            ConversationState::AwaitingApprovalCriteriaConfirm => null,

            ConversationState::AwaitingVisibility => ($visibility = ChallengeVisibility::tryFrom($value)) === null
                ? null
                : [ChallengeDraft::VISIBILITY => $visibility->value],

            ConversationState::AwaitingFlowType => ($flow = FlowType::tryFrom($value)) === null
                ? null
                : [ChallengeDraft::FLOW_TYPE => $flow->value],

            ConversationState::AwaitingStepInputType => ($input = StepInputType::tryFrom($value)) !== null
                && in_array($input, self::STEP_INPUT_TYPES, true)
                ? [ChallengeDraft::PENDING_STEP => ['input_type' => $input->value]]
                : null,

            ConversationState::AwaitingStepLabel => $value === self::SKIP
                ? $this->completePendingStep($draft, null)
                : null,

            default => null,
        };
    }

    /**
     * Say that a create-slot is needed, what one costs, and how to buy it.
     *
     * Delegated to {@see SlotRefusal} so the create and join refusals cannot drift
     * — they are the same three facts, and this one previously stopped at two of
     * them, quoting a price with nothing to tap. That made the refusal permanent:
     * the check is an entitlement *count*, not a balance, so no amount of coins
     * could ever clear it.
     */
    private function refuseForNoSlot(User $user): void
    {
        $this->refusal->send($user, EntitlementType::CreateSlot);
    }

    /**
     * Turn the draft into a challenge, or explain why not.
     */
    private function finish(User $user, BotConversation $conversation): void
    {
        // The privileged act, so the gate is asked again here and not only at
        // `/create` — the questions took minutes, and membership can lapse inside
        // one flow. The conversation is kept: they can join and tap Create again.
        if (! $this->gate->ensure($user)) {
            $this->gatePrompt->send($user);

            return;
        }

        $draft = ChallengeDraft::of($conversation);

        if (! $draft->isComplete()) {
            // Reachable when a deploy adds a question to a flow already in
            // progress. Cheaper to restart than to work out which answer is
            // missing and re-ask it out of order.
            $this->abandon($user);
            $this->buttons->send($user, 'bot.wizard.incomplete', 'create');

            return;
        }

        try {
            $challenge = $this->createChallenge->handle(
                creator: $user,
                title: (string) $draft->title(),
                description: $draft->description(),
                periodType: $draft->periodType() ?? PeriodType::Daily,
                customPeriodDays: $draft->customPeriodDays(),
                startsAt: $draft->startsAt(),
                totalPeriods: $draft->totalPeriods() ?? 1,
                timezone: (string) $draft->timezone(),
                proofType: $draft->proofType() ?? ProofType::Button,
                visibility: $draft->visibility() ?? ChallengeVisibility::InviteOnly,
                flowType: $draft->flowType(),
                steps: $draft->flowType() === FlowType::TimedSession ? $draft->steps() : null,
                approvalMode: $draft->approvalMode(),
                approvalCriteria: $draft->approvalCriteria(),
                scoringType: $draft->scoringType(),
                targetValue: $draft->scoringType()->isQuantity() ? $draft->targetValue() : null,
                unitLabel: $draft->scoringType()->isQuantity() ? $draft->unitLabel() : null,
                basePoints: $draft->scoringType()->isQuantity() ? $draft->basePoints() : null,
                quantityPartialCountsAsDone: $draft->scoringType()->isQuantity() && $draft->quantityPartialCountsAsDone(),
            );
        } catch (NoEntitlementAvailableException) {
            // They had a slot when the flow opened and spent it elsewhere since.
            $this->abandon($user);
            $this->refuseForNoSlot($user);

            return;
        } catch (InvalidArgumentException $refused) {
            // The wizard validated every answer, so this is our bug rather than
            // theirs. Told, logged, and the flow dropped so they are not stuck
            // tapping a button that will keep failing.
            Log::error('A wizard-built challenge was refused by CreateChallenge.', [
                'user_id' => $user->getKey(),
                'reason' => $refused->getMessage(),
            ]);

            $this->abandon($user);
            $this->buttons->send($user, 'bot.wizard.error', 'create');

            return;
        }

        // Deleted only once the challenge exists. A throw above leaves the flow
        // intact for the queue's retry rather than losing ten answers.
        $this->abandon($user);

        $this->announceOutcome($user, $challenge);
    }

    /**
     * Confirm the new challenge.
     *
     * The check-in line is here because a creator picks a proof type ten
     * questions before this message and never sees what it means from the
     * participant's side. It is the same sentence the participants will read, so
     * a creator who chose wrongly can tell before anyone has to check in.
     */
    private function announceOutcome(User $user, Challenge $challenge): void
    {
        $this->messenger->paragraphs($user, [
            $this->messenger->line($user, 'bot.wizard.created', ['title' => $challenge->title]),
            $this->messenger->line($user, 'bot.wizard.created_timeline', [
                'length' => $this->units->length($user, $challenge),
                'start' => $challenge->starts_at->setTimezone($challenge->timezone)->toDateString(),
                'timezone' => $challenge->timezone,
            ]),
            $this->messenger->line($user, 'bot.wizard.created_checkin', [
                'span' => $this->units->span($user, $challenge),
                'how' => $this->instructions->lineFor($user, $challenge),
            ]),
            $challenge->visibility->shouldAnnounce()
                ? $this->messenger->line($user, 'bot.wizard.created_public')
                : $this->messenger->line($user, 'bot.wizard.created_private'),
        ]);
    }

    /**
     * A date typed as `Y-m-d`, checked against the challenge's own calendar.
     *
     * Only an ISO date is accepted, not a localised one: the wizard serves Farsi
     * and English, and "03/04" means two different days to them. Today and
     * tomorrow are buttons for exactly that reason.
     */
    private function readDate(string $text, ChallengeDraft $draft): ?string
    {
        $text = str_replace(['/', '.'], '-', Localization::foldDigits($text));

        if (preg_match('/^\d{4}-\d{1,2}-\d{1,2}$/', $text) !== 1) {
            return null;
        }

        $timezone = $draft->timezone();

        if ($timezone === null) {
            // The timezone step runs first, so this is unreachable through the
            // wizard; refusing rather than guessing UTC keeps it that way.
            return null;
        }

        [$year, $month, $day] = array_map('intval', explode('-', $text));

        // `checkdate` before constructing, because Carbon happily rolls 31 April
        // over into 1 May rather than refusing it, and a creator who typed the
        // wrong month should be told rather than silently moved a day.
        if (! checkdate($month, $day, $year)) {
            return null;
        }

        $date = CarbonImmutable::create($year, $month, $day, 0, 0, 0, $timezone);

        // Yesterday would mean a period that closed before the challenge was
        // created, and a participant already behind on a challenge nobody could
        // have checked into.
        return $date->lessThan($this->localToday($draft)) ? null : $date->toDateString();
    }

    /**
     * Today, in the challenge's timezone rather than the server's.
     */
    private function localToday(ChallengeDraft $draft): CarbonImmutable
    {
        return CarbonImmutable::now($draft->timezone() ?? 'UTC')->startOfDay();
    }

    /**
     * A count typed by a person: Persian digits folded, bounds enforced.
     */
    private function positiveInteger(string $text, int $max): ?int
    {
        return $this->boundedInteger($text, 1, $max);
    }

    /**
     * A whole number typed by a person, between two bounds, or null.
     *
     * The step loop's bounds start at zero where the design allows it — a
     * zero-wait step is legal (it only means "no gate before this one") — so
     * this is the general form and `positiveInteger` the common special case.
     */
    private function boundedInteger(string $text, int $min, int $max): ?int
    {
        $text = Localization::foldDigits($text);

        if (! ctype_digit($text)) {
            return null;
        }

        $value = (int) $text;

        return $value >= $min && $value <= $max ? $value : null;
    }

    /**
     * The pending step, with one more field answered.
     *
     * @param  array<string, int|string>  $answer
     * @return array<string, int|string>
     */
    private function withPending(ChallengeDraft $draft, array $answer): array
    {
        return [...($draft->pendingStep() ?? []), ...$answer];
    }

    /**
     * Fold the half-gathered step into the finished list, label attached.
     *
     * The label is the loop's last question by construction, so answering it is
     * what finishes a step; the pending slot is emptied for the next lap.
     *
     * @return array<string, mixed> answers to merge into the draft
     */
    private function completePendingStep(ChallengeDraft $draft, ?string $label): array
    {
        return [
            ChallengeDraft::STEPS => [
                ...$draft->storedSteps(),
                [...($draft->pendingStep() ?? []), 'label' => $label],
            ],
            ChallengeDraft::PENDING_STEP => null,
        ];
    }

    /**
     * Answer "done" in the step loop: check the design, then confirm.
     *
     * The whole design is validated here rather than question by question,
     * because the one rule that matters — the waits summing to no more than one
     * period — is a property of the *list*, not of any single answer. The
     * numbers it fails by are already computed by the validator, so the refusal
     * can quote them rather than say "no".
     */
    private function handInSteps(User $user, BotConversation $conversation): void
    {
        $draft = ChallengeDraft::of($conversation);
        $periodType = $draft->periodType();
        $timezone = $draft->timezone();

        // The timeline questions come before the flow-type one, so a draft that
        // cannot answer them is a conversation that outlived a deploy — the
        // same verdict `finish()` gives an incomplete draft.
        if ($periodType === null || $timezone === null || $draft->startDate() === null) {
            $this->abandon($user);
            $this->buttons->send($user, 'bot.wizard.incomplete', 'create');

            return;
        }

        $steps = $draft->steps();

        if ($steps === []) {
            $this->ask($user, $conversation, [$this->say($user, $conversation, 'bot.wizard.awaiting_step_loop.error')]);

            return;
        }

        try {
            $this->stepDesign->handle($periodType, $draft->customPeriodDays(), $draft->startsAt(), $timezone, $steps);
        } catch (InvalidArgumentException) {
            $minimum = $this->stepDesign->minimumSeconds($steps);
            $periodSeconds = $this->stepDesign->periodSeconds(
                $periodType,
                $draft->customPeriodDays(),
                $draft->startsAt(),
                $timezone,
            );

            // Only the overrun gets its own sentence; anything else means a
            // step slipped past the per-question bounds, which is ours to fix.
            $this->ask($user, $conversation, [$minimum > $periodSeconds
                ? $this->messenger->line($user, 'bot.wizard.steps_too_long', [
                    'minimum' => CompactDuration::format($minimum),
                    'period' => CompactDuration::format($periodSeconds),
                ])
                : $this->say($user, $conversation, 'bot.wizard.awaiting_step_loop.error')]);

            return;
        }

        $conversation->advanceTo(ConversationState::AwaitingCreateConfirmation)->save();

        $this->ask($user, $conversation);
    }

    /**
     * Record who reviews the proofs, and open the criteria path AI review needs.
     *
     * Manual review is a one-answer step. AI review is not: it needs criteria,
     * and the recommended source is a suggestion generated from the challenge's
     * own title — asked for here so the suggestion is on screen before the
     * creator is asked to do anything with it.
     */
    private function chooseApprovalMode(User $user, BotConversation $conversation, ApprovalMode $mode): void
    {
        if ($mode === ApprovalMode::Manual) {
            $this->advance($user, $conversation, ConversationState::AwaitingApprovalMode, [
                ChallengeDraft::APPROVAL_MODE => ApprovalMode::Manual->value,
                ChallengeDraft::APPROVAL_CRITERIA => null,
                ChallengeDraft::CRITERIA_FROM_SUGGESTION => null,
            ]);

            return;
        }

        $draft = ChallengeDraft::of($conversation);

        // A stale button from further up the chat, or the admin withdrawing
        // the media type while a flow was open. Either way the tap cannot be
        // honoured, and the draft must not record a mode `CreateChallenge`
        // would refuse at hand-in.
        $proofType = $draft->proofType();

        if ($proofType === null || ! $this->aiApprovalGate->allows($proofType)) {
            $this->ask($user, $conversation, [
                $this->messenger->line($user, 'bot.wizard.awaiting_approval_mode.unavailable'),
            ]);

            return;
        }

        // Generated inside the conversation's job, not queued on its own: the
        // suggestion is what the very next message shows, and a queued call
        // would leave the flow parked on a prompt that has nothing to show.
        $suggestion = $this->suggestCriteria->suggest((string) $draft->title(), $draft->description());

        if ($suggestion === null) {
            // No provider answered. AI review is unavailable *now*; the
            // creator still gets their challenge, with a typed criteria if the
            // screening capability is up, and manual review if it is not.
            $conversation->advanceTo(ConversationState::AwaitingApprovalMode, [
                ChallengeDraft::APPROVAL_MODE => ApprovalMode::Ai->value,
            ])->advanceTo(ConversationState::AwaitingApprovalCriteria)->save();

            $this->ask($user, $conversation, [$this->messenger->line($user, 'bot.wizard.criteria_no_suggestion')]);

            return;
        }

        $conversation->advanceTo(ConversationState::AwaitingApprovalMode, [
            ChallengeDraft::APPROVAL_MODE => ApprovalMode::Ai->value,
            ChallengeDraft::APPROVAL_CRITERIA => $suggestion,
            ChallengeDraft::CRITERIA_FROM_SUGGESTION => '1',
        ])->advanceTo(ConversationState::AwaitingApprovalCriteriaConfirm)->save();

        $this->ask($user, $conversation);
    }

    /**
     * Accept the suggested criteria, or move to typing one's own.
     *
     * The suggestion rides in the draft from the moment it is generated, so
     * accepting is just leaving it there — and the draft is what survives a
     * deploy between the two taps, not the keyboard the buttons came on.
     */
    private function answerCriteriaChoice(User $user, BotConversation $conversation, string $value): void
    {
        if ($value === self::CONFIRM) {
            $conversation->advanceTo(ConversationState::AwaitingApprovalCriteriaConfirm)
                ->advanceTo(ConversationState::AwaitingVisibility)->save();

            $this->ask($user, $conversation);

            return;
        }

        $conversation->advanceTo(ConversationState::AwaitingApprovalCriteriaConfirm, [
            ChallengeDraft::APPROVAL_CRITERIA => null,
            ChallengeDraft::CRITERIA_FROM_SUGGESTION => null,
        ])->advanceTo(ConversationState::AwaitingApprovalCriteria)->save();

        $this->ask($user, $conversation);
    }

    /**
     * Screen a typed criteria and carry the verdict.
     *
     * The one place creator-written text can enter `approval_criteria`. A
     * clean verdict stores it; anything else falls back to manual review with
     * the attempt logged for an admin — never stored, never silently dropped.
     */
    private function receiveTypedCriteria(User $user, BotConversation $conversation, string $text): void
    {
        $text = trim($text);
        $max = CreateChallenge::limits()['approval_criteria_max'];

        if ($text === '' || mb_strlen($text) > $max) {
            $this->ask($user, $conversation, [$this->say($user, $conversation, 'bot.wizard.awaiting_approval_criteria.error')]);

            return;
        }

        $screening = $this->screenCriteria->screen($text, $user);

        if ($screening->verdict === ApprovalCriteriaVerdict::Clean) {
            $conversation->advanceTo(ConversationState::AwaitingApprovalCriteria, [
                ChallengeDraft::APPROVAL_CRITERIA => $text,
                ChallengeDraft::CRITERIA_FROM_SUGGESTION => null,
            ])->advanceTo(ConversationState::AwaitingVisibility)->save();

            $this->ask($user, $conversation);

            return;
        }

        // Flagged, or the filter could not be reached. Either way the text is
        // not stored and the challenge is created with a human reviewer; the
        // screening row is the admin's record of what was attempted.
        $conversation->advanceTo(ConversationState::AwaitingApprovalCriteria, [
            ChallengeDraft::APPROVAL_MODE => ApprovalMode::Manual->value,
            ChallengeDraft::APPROVAL_CRITERIA => null,
            ChallengeDraft::CRITERIA_FROM_SUGGESTION => null,
        ])->advanceTo(ConversationState::AwaitingVisibility)->save();

        $this->ask($user, $conversation, [$this->messenger->line($user, $screening->verdict === ApprovalCriteriaVerdict::Flagged
            ? 'bot.wizard.criteria_flagged'
            : 'bot.wizard.criteria_unscreened')]);
    }

    /**
     * Confirm the conversation belongs to this wizard.
     *
     * @throws LogicException when the router handed over a check-in conversation
     */
    private function assertOwnState(BotConversation $conversation): ConversationState
    {
        $state = $conversation->state;

        if (! $state->isCreateChallengeStep()) {
            throw new LogicException("{$state->value} is not a create-challenge step.");
        }

        return $state;
    }
}
