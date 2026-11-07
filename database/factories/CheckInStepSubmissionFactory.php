<?php

namespace Database\Factories;

use App\Models\ChallengeStep;
use App\Models\CheckInSession;
use App\Models\CheckInStepSubmission;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CheckInStepSubmission>
 */
class CheckInStepSubmissionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * A button step's answer: on time, nothing stored. Image and voice steps
     * set `proof_path` to the same shape `check_ins.proof_path` uses.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'check_in_session_id' => CheckInSession::factory(),
            'challenge_step_id' => ChallengeStep::factory(),
            'submitted_at' => now(),
            'proof_path' => null,
        ];
    }

    /**
     * An answer carrying media, mirroring the image-approval storage path.
     */
    public function withProof(string $path): static
    {
        return $this->state(fn (array $attributes): array => [
            'proof_path' => $path,
        ]);
    }
}
