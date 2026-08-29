<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Cache;
use Symfony\Component\Process\Process;

/**
 * Whether this deployment's real environment can run ffmpeg — asked rarely,
 * answered from cache.
 *
 * Shared hosting typically does not ship ffmpeg, and the frame-sampling video
 * review path (Phase 14 Task 4) is dead weight without it, so the platform
 * refuses to *offer* video AI review rather than failing per submission. The
 * check is a real process spawn (`-version` exits 0 and prints a version
 * banner only when the binary actually runs), never a `which` lookup — a
 * shim on PATH that cannot execute must read as absent.
 *
 * Detection is cached for the probe TTL: admin settings load it, and the
 * runtime verdict router asks again on every video submission, so an
 * uncached probe would spawn two processes per check for an answer that
 * changes on the scale of deployments, not requests. The database cache
 * driver is fine — this is exactly the low-write, low-value shape it is for.
 */
class FfmpegDetector
{
    private const CACHE_KEY = 'ai.review.ffmpeg_present';

    private const PROBE_TTL_MINUTES = 15;

    private const PROBE_TIMEOUT_SECONDS = 10;

    /**
     * The binaries the sampling path needs: ffmpeg to extract frames,
     * ffprobe to read the duration the even spacing is computed from.
     */
    private const BINARIES = ['ffmpeg', 'ffprobe'];

    public function present(): bool
    {
        return (bool) Cache::remember(
            self::CACHE_KEY,
            now()->addMinutes(self::PROBE_TTL_MINUTES),
            fn (): bool => $this->probe(),
        );
    }

    /**
     * Spawn each binary's `-version` and demand a clean exit.
     */
    protected function probe(): bool
    {
        foreach (self::BINARIES as $binary) {
            $process = new Process([$binary, '-version']);
            $process->setTimeout(self::PROBE_TIMEOUT_SECONDS);
            $process->run();

            if (! $process->isSuccessful()) {
                return false;
            }
        }

        return true;
    }
}
