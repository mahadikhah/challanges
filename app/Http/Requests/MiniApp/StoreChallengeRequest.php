<?php

namespace App\Http\Requests\MiniApp;

use App\Actions\Challenges\CreateChallenge;
use App\Enums\ChallengeVisibility;
use App\Enums\PeriodType;
use App\Enums\ProofType;
use App\Enums\ScoringType;
use App\Enums\SettingKey;
use App\Services\Settings;
use DateTimeZone;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The Mini App's create form, as the friendly layer in front of
 * `CreateChallenge`.
 *
 * **Every bound here is read, never retyped.** The character limits come from
 * `CreateChallenge::limits()`, the timezone list is the same
 * `DateTimeZone::listIdentifiers()` the action validates against, and the media
 * ceilings are the two `Setting`s `assertMediaCaps()` clamps to. That is what
 * keeps the two layers from drifting: the action stays the floor — what must
 * hold whichever surface called — and this request only says the same thing
 * earlier, in a shape the SPA can render field by field.
 *
 * The required/forbidden pairs are the action's own rules said earlier, not a
 * friendlier policy invented here — but they are not uniform, because the
 * action's are not. `assertScoring()` **refuses** a stray target on a binary
 * challenge, so a scoring field that arrives where it has no meaning is
 * `prohibited`. `assertMediaCaps()` and the day count **degrade** instead: a
 * cap on a proof type that wants none is a UI leftover, not bad intent — the
 * form's own user picked a voice challenge, set the caps, and then changed
 * their mind, and refusing would block a submission over a field the screen no
 * longer shows. So those two are dropped by the action rather than rejected
 * here, and this request keeps only the bounds.
 *
 * **No `flow_type` field.** This build creates simple challenges only — the
 * action's default — and accepting a field with one legal value is validation
 * surface for no information. Task 7 adds it, along with the step list and the
 * approval mode, to this same request.
 *
 * The start date is deliberately *only* shape-checked here. "Not before today"
 * cannot be a rule on this request: the today it means is the challenge's own
 * timezone, which is a sibling field, so the comparison belongs where both are
 * known — see `CreateChallengeController::store()`.
 */
class StoreChallengeRequest extends FormRequest
{
    /**
     * The route already carries `auth:sanctum` and `ability:miniapp`, so the
     * actor is established by the time a rule runs. There is no second
     * condition to check: creating is available to every authenticated user,
     * and the one thing that can stop them — holding no create-slot — is a
     * 402 from the action, not an authorization failure.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $limits = CreateChallenge::limits();
        $settings = app(Settings::class);

        return [
            'title' => ['required', 'string', 'min:3', 'max:'.$limits['title_max']],
            'description' => ['nullable', 'string', 'max:'.$limits['description_max']],

            'period_type' => ['required', 'string', 'in:'.$this->valuesOf(PeriodType::class)],
            // Required for `custom` and ignored by the five fixed types: the
            // action stores null for them, so a leftover count is dropped
            // rather than refused. It is still bounded, so a count that would
            // be refused on a custom challenge cannot slip through here.
            'custom_period_days' => [
                'required_if:period_type,'.PeriodType::Custom->value,
                'nullable', 'integer', 'min:1', 'max:'.$limits['custom_period_days_max'],
            ],

            // The wizard asks this before the start date for the same reason it
            // is listed here: "starting on the 3rd" is not an instant until the
            // zone is known, and the two are validated against each other.
            'timezone' => ['required', 'string', 'in:'.implode(',', DateTimeZone::listIdentifiers())],

            // ISO only. A browser-localised string means two different days to
            // the two locales this app serves, so the SPA is told to send
            // `Y-m-d` and nothing else is accepted.
            'starts_at' => ['required', 'date_format:Y-m-d'],

            'total_periods' => ['required', 'integer', 'min:1', 'max:'.$limits['total_periods_max']],

            'proof_type' => ['required', 'string', 'in:'.$this->valuesOf(ProofType::class)],
            'visibility' => ['required', 'string', 'in:'.$this->valuesOf(ChallengeVisibility::class)],

            // Declared rather than inferred so `quantity` is a first-class
            // choice the picker can render, and so a scoring block arriving
            // without it is refused rather than guessed at.
            'scoring_type' => ['required', 'string', 'in:'.$this->valuesOf(ScoringType::class)],
            'target_value' => [
                'required_if:scoring_type,'.ScoringType::Quantity->value,
                'prohibited_if:scoring_type,'.ScoringType::Binary->value,
                'nullable', 'numeric', 'gt:0',
            ],
            'unit_label' => [
                'required_if:scoring_type,'.ScoringType::Quantity->value,
                'prohibited_if:scoring_type,'.ScoringType::Binary->value,
                'nullable', 'string', 'max:'.$limits['unit_label_max'],
            ],
            'base_points' => [
                'required_if:scoring_type,'.ScoringType::Quantity->value,
                'prohibited_if:scoring_type,'.ScoringType::Binary->value,
                'nullable', 'numeric', 'gt:0',
            ],
            'quantity_partial_counts_as_done' => [
                'prohibited_if:scoring_type,'.ScoringType::Binary->value,
                'nullable', 'boolean',
            ],

            // Required the moment a recording is involved — `ProofType::expectsDuration()`'s
            // rule, said twice because a Laravel rule cannot ask the enum. The
            // ceiling is the admin's, because disk is the platform's to run out
            // of, and it is read here so the form clamps to the same number the
            // validator enforces. A cap left behind by a changed proof type is
            // dropped by the action, not refused here.
            'proof_media_max_seconds' => [
                'required_if:proof_type,'.ProofType::VoiceApproval->value,
                'required_if:proof_type,'.ProofType::VideoApproval->value,
                'nullable', 'integer', 'min:1',
                'max:'.$settings->integer(SettingKey::ProofMediaMaxSeconds),
            ],
            'proof_media_max_size_kb' => [
                'required_if:proof_type,'.ProofType::VoiceApproval->value,
                'required_if:proof_type,'.ProofType::VideoApproval->value,
                'nullable', 'integer', 'min:1',
                'max:'.$settings->integer(SettingKey::ProofMediaMaxSizeKb),
            ],
        ];
    }

    /**
     * An enum's case values as the `in:` list the validator wants.
     *
     * Built from `cases()` rather than written out, so a case added to an enum
     * is accepted here the day it exists rather than the day somebody
     * remembers this file.
     *
     * @param  class-string<\BackedEnum>  $enum
     */
    private function valuesOf(string $enum): string
    {
        return implode(',', array_map(
            static fn (\BackedEnum $case): string => (string) $case->value,
            $enum::cases(),
        ));
    }
}
