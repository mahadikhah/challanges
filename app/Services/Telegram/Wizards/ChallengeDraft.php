<?php

namespace App\Services\Telegram\Wizards;

use App\Enums\ChallengeVisibility;
use App\Enums\PeriodType;
use App\Enums\ProofType;
use App\Models\BotConversation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use RuntimeException;

/**
 * The half-built challenge a wizard conversation is carrying.
 *
 * A typed reading of `BotConversation::$payload`, which is otherwise an untyped
 * JSON blob. Everything in it came from a Telegram message or a button, so every
 * accessor tolerates the answer being absent or the wrong shape — a conversation
 * row can outlive a deploy that changed what the wizard asks.
 *
 * **The payload stores scalars, never objects.** It round-trips through JSON, so a
 * `PeriodType` is stored as its backing value and a start date as `Y-m-d`; this is
 * where those become domain types again. The date in particular is deliberately not
 * an instant: "starting on the 3rd" has no meaning until the timezone is known, and
 * the wizard asks for the timezone first precisely so that `startsAt()` can be
 * computed rather than guessed.
 */
readonly class ChallengeDraft
{
    public const string TITLE = 'title';

    public const string DESCRIPTION = 'description';

    public const string PERIOD_TYPE = 'period_type';

    public const string CUSTOM_PERIOD_DAYS = 'custom_period_days';

    public const string TIMEZONE = 'timezone';

    public const string START_DATE = 'start_date';

    public const string TOTAL_PERIODS = 'total_periods';

    public const string PROOF_TYPE = 'proof_type';

    public const string VISIBILITY = 'visibility';

    /**
     * @param  array<string, mixed>  $answers
     */
    private function __construct(private array $answers) {}

    /**
     * The draft gathered so far in this conversation.
     */
    public static function of(BotConversation $conversation): self
    {
        return new self($conversation->payload ?? []);
    }

    public function title(): ?string
    {
        return $this->text(self::TITLE);
    }

    public function description(): ?string
    {
        return $this->text(self::DESCRIPTION);
    }

    public function periodType(): ?PeriodType
    {
        $value = $this->text(self::PERIOD_TYPE);

        return $value === null ? null : PeriodType::tryFrom($value);
    }

    /**
     * The day count, for a `custom` challenge only.
     *
     * Null for every other period type even if a stale answer is still in the
     * payload — a creator who picked `custom`, typed 5, then went back and chose
     * `weekly` must not end up with a five-day week.
     */
    public function customPeriodDays(): ?int
    {
        if ($this->periodType()?->requiresCustomDays() !== true) {
            return null;
        }

        return $this->number(self::CUSTOM_PERIOD_DAYS);
    }

    public function timezone(): ?string
    {
        return $this->text(self::TIMEZONE);
    }

    /**
     * The chosen calendar date, `Y-m-d`, as read in the challenge's timezone.
     */
    public function startDate(): ?string
    {
        return $this->text(self::START_DATE);
    }

    public function totalPeriods(): ?int
    {
        return $this->number(self::TOTAL_PERIODS);
    }

    public function proofType(): ?ProofType
    {
        $value = $this->text(self::PROOF_TYPE);

        return $value === null ? null : ProofType::tryFrom($value);
    }

    public function visibility(): ?ChallengeVisibility
    {
        $value = $this->text(self::VISIBILITY);

        return $value === null ? null : ChallengeVisibility::tryFrom($value);
    }

    /**
     * Midnight on the chosen date, in the challenge's timezone, as UTC.
     *
     * A date rather than a time because that is what the wizard asks for, and
     * midnight local because that is where a daily period boundary falls. The
     * conversion happens here and not at the call site: handing a Tehran-local
     * Carbon to the query builder would write the local wall clock into a UTC
     * column and lose three and a half hours without complaining.
     *
     * @throws RuntimeException when the draft has not gathered enough to say
     */
    public function startsAt(): CarbonImmutable
    {
        $date = $this->startDate();
        $timezone = $this->timezone();

        if ($date === null || $timezone === null) {
            throw new RuntimeException('The draft has no start date or no timezone yet.');
        }

        return CarbonImmutable::parse($date, $timezone)->startOfDay()->utc();
    }

    /**
     * Whether every question needed to create the challenge has an answer.
     *
     * The gate on the confirmation step: a conversation can be resumed after a
     * deploy that added a question, and the answer to a question that was never
     * asked is missing rather than wrong.
     */
    public function isComplete(): bool
    {
        $required = [
            $this->title(),
            $this->periodType(),
            $this->timezone(),
            $this->startDate(),
            $this->totalPeriods(),
            $this->proofType(),
            $this->visibility(),
        ];

        foreach ($required as $answer) {
            if ($answer === null) {
                return false;
            }
        }

        return ! ($this->periodType()?->requiresCustomDays() === true && $this->customPeriodDays() === null);
    }

    /**
     * A trimmed non-empty string answer, or null.
     */
    private function text(string $key): ?string
    {
        $value = Arr::get($this->answers, $key);

        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * A positive integer answer, or null.
     */
    private function number(string $key): ?int
    {
        $value = Arr::get($this->answers, $key);

        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && ctype_digit($value) ? (int) $value : null;
    }
}
