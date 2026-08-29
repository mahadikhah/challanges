<?php

namespace App\Console\Commands\Observability;

use App\Actions\Observability\RecordSchedulerHeartbeat;
use Illuminate\Console\Command;

/**
 * Cron's minute hand on its own pulse.
 *
 * Scheduled `everyMinute()` in routes/console.php, and therefore the one
 * command whose successful run is itself the evidence that cron works: the
 * stamp it writes is what the System Health page (Task 4) and the stale
 * heartbeat alert (Task 5) read.
 *
 * The optional external ping lives in the action, not here, so the command's
 * exit code can only ever reflect the stamp — a monitoring service being
 * unreachable must not look like the scheduler failing.
 */
class RecordHeartbeat extends Command
{
    protected $signature = 'observability:heartbeat';

    protected $description = 'Stamp the scheduler heartbeat and optionally ping the external dead-man\'s switch';

    public function handle(RecordSchedulerHeartbeat $heartbeat): int
    {
        $heartbeat->handle();

        return self::SUCCESS;
    }
}
