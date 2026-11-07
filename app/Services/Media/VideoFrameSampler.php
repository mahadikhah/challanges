<?php

namespace App\Services\Media;

use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Extracts a few evenly-spaced still frames from a stored video, for the one
 * thing that wants them: AI review (Phase 14 Task 4).
 *
 * No catalog driver accepts video bytes as a prompt attachment, so a video
 * reaches the model as a *set of frames* — a fixed handful, spread evenly
 * across the recording, judged together as one submission. The frames are
 * temporary scratch files: `withFrames()` hands them to one callback and
 * deletes the directory in a `finally`, because review is the only reader
 * and a stray frame directory is proof media outliving its retention window
 * by another name.
 *
 * Every failure throws — unreadable duration, a failed extraction, a video
 * shorter than the frame spacing — because the caller runs this inside the
 * provider chain's attempt closure: failing the attempt rotates accounts or
 * lands the submission in the manual queue, which is the only honest answer
 * to a video nobody could watch.
 */
class VideoFrameSampler
{
    /**
     * How many frames one review sees. Four sits inside the spec's 3–5 band:
     * enough for beginning/middle/end plus one, few enough that each frame
     * stays a meaningful share of the evidence rather than noise.
     */
    public const FRAME_COUNT = 4;

    private const EXTRACT_TIMEOUT_SECONDS = 60;

    /**
     * Run `$callback` with the extracted frames' absolute paths, then remove
     * them whatever happens.
     *
     * @template T
     *
     * @param  callable(list<string>): T  $callback
     * @return T
     *
     * @throws RuntimeException when the video cannot be sampled
     */
    public function withFrames(string $path, callable $callback, string $disk = 'local'): mixed
    {
        $absolute = Storage::disk($disk)->path($path);

        if (! is_file($absolute)) {
            throw new RuntimeException("The stored video [{$path}] does not exist.");
        }

        $duration = $this->duration($absolute);

        // Spaced by duration/(count+1): first and last frames sit inside the
        // recording rather than on its edges, where encoders park black or
        // half-formed frames. System temp, not a storage disk — the frames
        // are scratch for one provider call, never proof media.
        $directory = sys_get_temp_dir().'/video-frames-'.uniqid('', true);

        if (! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException('The frame scratch directory could not be created.');
        }

        try {
            $frames = [];

            foreach (range(1, self::FRAME_COUNT) as $index) {
                $at = ($duration * $index) / (self::FRAME_COUNT + 1);
                $frame = "{$directory}/frame-{$index}.jpg";

                $this->run(['ffmpeg', '-y', '-ss', (string) $at, '-i', $absolute, '-frames:v', '1', '-q:v', '4', $frame]);

                if (! is_file($frame) || filesize($frame) === 0) {
                    throw new RuntimeException("Frame {$index} of the video could not be extracted.");
                }

                $frames[] = $frame;
            }

            return $callback($frames);
        } finally {
            foreach (glob("{$directory}/*.jpg") ?: [] as $file) {
                @unlink($file);
            }

            @rmdir($directory);
        }
    }

    /**
     * The recording's duration in seconds, via ffprobe.
     */
    private function duration(string $absolute): float
    {
        $output = $this->run([
            'ffprobe', '-v', 'error',
            '-show_entries', 'format=duration',
            '-of', 'default=noprint_wrappers=1:nokey=1',
            $absolute,
        ]);

        $duration = filter_var(trim($output), FILTER_VALIDATE_FLOAT);

        if ($duration === false || $duration <= 0) {
            throw new RuntimeException('The video duration could not be read.');
        }

        return $duration;
    }

    /**
     * @param  list<string>  $command
     * @return string the process output
     *
     * @throws RuntimeException when the command fails
     */
    private function run(array $command): string
    {
        $process = new Process($command);
        $process->setTimeout(self::EXTRACT_TIMEOUT_SECONDS);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException('The video sampling toolchain failed: '.trim($process->getErrorOutput()));
        }

        return $process->getOutput();
    }
}
