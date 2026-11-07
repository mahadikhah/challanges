<?php

namespace App\Actions\CheckIns;

use App\Enums\CheckInSessionStatus;
use App\Enums\CheckInStatus;
use App\Enums\SettingKey;
use App\Models\CheckIn;
use App\Models\CheckInStepSubmission;
use App\Services\Settings;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Storage;

/**
 * Delete the media of decided submissions, and keep everything else.
 *
 * Storage is a real constraint on shared hosting: video proof submitted
 * regularly by many participants fills a disk quota faster than any other
 * thing the platform stores. The decision a submission produced, though, is
 * worth keeping forever — a verdict that can no longer be examined is a
 * verdict the participant cannot appeal. So the split is bytes vs. record:
 * after the admin-tuned retention window, the file goes and the row stays,
 * with its status, reviewer and timestamps intact.
 *
 * **What counts as decided.** A check-in whose status is no longer `Pending`
 * (nothing submitted) or `Submitted` (awaiting a human). `Rejected` counts:
 * a rejected row can only be resubmitted while its period is open, and the
 * retention window is far longer than any period. `Missed` and `Frozen` are
 * the rollover's verdicts on rows that were still undecided at close — there
 * is nobody left to show the media to, but the record of what happened is
 * kept like any other.
 *
 * **Step-submission media follows its session.** A timed session's steps are
 * never reviewed one by one; the completed (or expired, or abandoned) session
 * is the decision. A session still in progress may yet be completed by the
 * participant, so its media is never touched.
 *
 * The window is measured from the decision (`reviewed_at`, else the
 * rollover's `updated_at`) for check-ins and from `submitted_at` for step
 * submissions — the earliest moment the bytes stopped mattering.
 *
 * Re-runs are free: a pruned row carries `proof_path = null`, so the query
 * skips it and nothing is deleted twice.
 */
class PruneProofMedia
{
    public function __construct(private readonly Settings $settings) {}

    /**
     * Prune every out-of-window proof file.
     *
     * @return int how many files were deleted across both submission tables
     */
    public function handle(?CarbonInterface $now = null): int
    {
        $cutoff = CarbonImmutable::instance($now ?? now())
            ->subDays($this->settings->integer(SettingKey::ProofMediaRetentionDays));

        return $this->pruneCheckIns($cutoff) + $this->pruneStepSubmissions($cutoff);
    }

    /**
     * @return list<CheckIn>
     */
    private function dueCheckIns(CarbonImmutable $cutoff): array
    {
        return array_values(CheckIn::query()
            ->whereNotNull('proof_path')
            ->whereIn('status', [
                CheckInStatus::Approved,
                CheckInStatus::Rejected,
                CheckInStatus::Missed,
                CheckInStatus::Frozen,
            ])
            ->where(
                fn ($query) => $query
                    ->whereNotNull('reviewed_at')->where('reviewed_at', '<=', $cutoff)
                    ->orWhere(fn ($rows) => $rows->whereNull('reviewed_at')->where('updated_at', '<=', $cutoff)),
            )
            ->get()
            ->all());
    }

    private function pruneCheckIns(CarbonImmutable $cutoff): int
    {
        $pruned = 0;

        foreach ($this->dueCheckIns($cutoff) as $checkIn) {
            $pruned += $this->forgetFile($checkIn->proof_path);
            $checkIn->update(['proof_path' => null]);
        }

        return $pruned;
    }

    private function pruneStepSubmissions(CarbonImmutable $cutoff): int
    {
        $pruned = 0;

        CheckInStepSubmission::query()
            ->whereNotNull('proof_path')
            ->where('submitted_at', '<=', $cutoff)
            ->whereHas(
                'session',
                fn ($sessions) => $sessions->where('status', '!=', CheckInSessionStatus::InProgress),
            )
            ->each(function (CheckInStepSubmission $submission) use (&$pruned): void {
                $pruned += $this->forgetFile($submission->proof_path);
                $submission->update(['proof_path' => null]);
            });

        return $pruned;
    }

    /**
     * @return int 1 when a file was deleted, 0 when it was already gone — a
     *             missing file must not stop the record being cleared, because
     *             a half-pruned row is one this job re-visits forever
     */
    private function forgetFile(?string $path): int
    {
        if ($path === null || ! Storage::disk('local')->exists($path)) {
            return 0;
        }

        return Storage::disk('local')->delete($path) ? 1 : 0;
    }
}
