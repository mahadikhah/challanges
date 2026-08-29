<?php

namespace App\Console\Commands\Challenges;

use App\Actions\CheckIns\PruneProofMedia;
use Illuminate\Console\Command;

/**
 * The proof-media retention window's daily enforcement.
 *
 * Scheduled daily rather than with the minute-cadence clockwork: nothing is
 * waiting on it — the decision record survives the file by design, and the
 * disk a pruned file frees is freed just the same a few hours later.
 */
class PruneProofMediaCommand extends Command
{
    protected $signature = 'challenges:prune-proof-media';

    protected $description = 'Delete media files of decided submissions past the retention window, keeping the decision records';

    public function handle(PruneProofMedia $prune): int
    {
        $pruned = $prune->handle();

        $this->components->twoColumnDetail('Proof media files pruned', (string) $pruned);

        return self::SUCCESS;
    }
}
