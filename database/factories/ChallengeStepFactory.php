<?php

namespace Database\Factories;

use App\Enums\StepInputType;
use App\Models\Challenge;
use App\Models\ChallengeStep;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChallengeStep>
 */
class ChallengeStepFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * A five-minute button step: the smallest design that still exercises the
     * wait gate. Voice and image variations come from the states below.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'challenge_id' => Challenge::factory(),
            'step_order' => 1,
            'input_type' => StepInputType::Button,
            'min_wait_seconds' => 300,
            'voice_max_seconds' => null,
            'label' => fake()->sentence(4),
        ];
    }

    /**
     * A voice step, carrying the cap that makes one coherent.
     */
    public function voice(int $maxSeconds = 60): static
    {
        return $this->state(fn (array $attributes): array => [
            'input_type' => StepInputType::Voice,
            'voice_max_seconds' => $maxSeconds,
        ]);
    }

    /**
     * An image step.
     */
    public function image(): static
    {
        return $this->state(fn (array $attributes): array => [
            'input_type' => StepInputType::Image,
            'voice_max_seconds' => null,
        ]);
    }

    /**
     * A video step. Its duration and size caps live on the challenge, not the
     * step — a recording challenge records one pair of caps for every kind of
     * recording it accepts, so there is no per-step number to carry.
     */
    public function video(): static
    {
        return $this->state(fn (array $attributes): array => [
            'input_type' => StepInputType::Video,
            'voice_max_seconds' => null,
        ]);
    }

    /**
     * Position in the sequence; 1-based, as the session machinery expects.
     */
    public function atOrder(int $order): static
    {
        return $this->state(fn (array $attributes): array => [
            'step_order' => $order,
        ]);
    }

    /**
     * The step's gate, in seconds.
     */
    public function waiting(int $seconds): static
    {
        return $this->state(fn (array $attributes): array => [
            'min_wait_seconds' => $seconds,
        ]);
    }

    /**
     * A proposed three-step design whose waits sum to `$totalWaitSeconds`.
     *
     * The validator consumes proposed lists, not rows, so the "valid design"
     * and "invalid design" factory states are constructors of proposals: a
     * test derives the period's length from the materialiser, then asks for a
     * design just under it (valid) or just over it (invalid) — no
     * seconds-per-period constant lives in the test.
     *
     * @return list<array{input_type: StepInputType, min_wait_seconds: int, voice_max_seconds: int|null, label: string}>
     */
    public static function design(int $totalWaitSeconds): array
    {
        $first = intdiv($totalWaitSeconds, 2);
        $second = $totalWaitSeconds - $first;

        return [
            ['input_type' => StepInputType::Button, 'min_wait_seconds' => $first, 'voice_max_seconds' => null, 'label' => 'First pause'],
            ['input_type' => StepInputType::Voice, 'min_wait_seconds' => $second, 'voice_max_seconds' => 90, 'label' => 'Say what you did'],
        ];
    }
}
