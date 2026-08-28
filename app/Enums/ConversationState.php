<?php

namespace App\Enums;

/**
 * Where a user is inside a multi-step bot flow.
 *
 * The Telegram SDK has no FSM, so a wizard's position lives in a
 * `BotConversation` row rather than in memory. Absence of a row means the user
 * is idle — there is deliberately no `Idle` case, because a row that says
 * "nothing is happening" is a row that can be left behind.
 *
 * Unlike the other domain enums this one is **not** translated: these states are
 * internal machinery and never shown to a user. The prompts a user sees are
 * separate lang lines, chosen by the wizard.
 */
enum ConversationState: string
{
    case AwaitingChallengeTitle = 'awaiting_challenge_title';
    case AwaitingChallengeDescription = 'awaiting_challenge_description';
    case AwaitingPeriodType = 'awaiting_period_type';
    case AwaitingCustomPeriodDays = 'awaiting_custom_period_days';
    case AwaitingStartDate = 'awaiting_start_date';
    case AwaitingTotalPeriods = 'awaiting_total_periods';
    case AwaitingTimezone = 'awaiting_timezone';
    case AwaitingProofType = 'awaiting_proof_type';
    case AwaitingApprovalMode = 'awaiting_approval_mode';
    case AwaitingApprovalCriteria = 'awaiting_approval_criteria';
    case AwaitingApprovalCriteriaConfirm = 'awaiting_approval_criteria_confirm';
    case AwaitingVisibility = 'awaiting_visibility';
    case AwaitingFlowType = 'awaiting_flow_type';
    case AwaitingStepLoop = 'awaiting_step_loop';
    case AwaitingStepInputType = 'awaiting_step_input_type';
    case AwaitingStepWait = 'awaiting_step_wait';
    case AwaitingStepVoiceLimit = 'awaiting_step_voice_limit';
    case AwaitingStepLabel = 'awaiting_step_label';
    case AwaitingCreateConfirmation = 'awaiting_create_confirmation';

    case AwaitingCheckInText = 'awaiting_check_in_text';
    case AwaitingCheckInPhoto = 'awaiting_check_in_photo';

    case AwaitingChatForward = 'awaiting_chat_forward';

    /**
     * Whether this state belongs to the create-challenge wizard.
     *
     * The sequence itself is not encoded here: it branches (custom day count is
     * only asked for `PeriodType::Custom`), so the wizard action owns the order.
     */
    public function isCreateChallengeStep(): bool
    {
        return ! $this->isCheckInStep() && ! $this->isChatLinkStep();
    }

    /**
     * Whether this state is waiting on check-in proof.
     */
    public function isCheckInStep(): bool
    {
        return match ($this) {
            self::AwaitingCheckInText, self::AwaitingCheckInPhoto => true,
            default => false,
        };
    }

    /**
     * Whether this state is waiting on the chat-link flow's forwarded message.
     */
    public function isChatLinkStep(): bool
    {
        return $this === self::AwaitingChatForward;
    }

    /**
     * Whether a plain text message is the expected next input.
     */
    public function expectsText(): bool
    {
        return match ($this) {
            self::AwaitingChallengeTitle,
            self::AwaitingChallengeDescription,
            self::AwaitingCustomPeriodDays,
            self::AwaitingStartDate,
            self::AwaitingTotalPeriods,
            self::AwaitingApprovalCriteria,
            self::AwaitingStepWait,
            self::AwaitingStepVoiceLimit,
            self::AwaitingStepLabel,
            self::AwaitingCheckInText => true,
            default => false,
        };
    }

    /**
     * Whether a photo is the expected next input.
     *
     * The chat-link step is absent by design: a forwarded message can carry
     * anything — text, a photo, a sticker — and the flow judges the *forward*,
     * not the content, so its router branch reads the whole update instead of
     * asking this predicate.
     */
    public function expectsPhoto(): bool
    {
        return $this === self::AwaitingCheckInPhoto;
    }

    /**
     * Whether the answer arrives as an inline-keyboard callback rather than a
     * typed message.
     */
    public function expectsCallback(): bool
    {
        return ! $this->expectsText() && ! $this->expectsPhoto();
    }
}
