<?php

namespace App\Console\Commands\Payments;

use App\Actions\Payments\SweepAbandonedStarPayments;
use Illuminate\Console\Command;

/**
 * The payments audit's daily tidy-up.
 *
 * Scheduled daily rather than with the minute-cadence clockwork: this is not
 * time-sensitive work, and running it often would only mean sweeping shorter
 * and shorter hesitations at a payment sheet.
 */
class SweepAbandonedPaymentsCommand extends Command
{
    protected $signature = 'payments:sweep-abandoned';

    protected $description = 'Mark pending Star payments older than the abandonment window as failed';

    public function handle(SweepAbandonedStarPayments $sweep): int
    {
        $swept = $sweep->handle();

        $this->components->twoColumnDetail('Abandoned payments swept', (string) $swept);

        return self::SUCCESS;
    }
}
