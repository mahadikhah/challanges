<?php

namespace App\Console\Commands\Observability;

use App\Enums\SettingKey;
use App\Services\Settings;
use Illuminate\Console\Command;

/**
 * The Telescope retention window's daily enforcement.
 *
 * The window is read here rather than where the schedule is defined because
 * console routes load on every application boot: a Setting resolved there
 * would warm the settings cache before a request has done anything, freezing
 * the value every reader of that process sees until the cache is flushed.
 * Read here, it is resolved once a day, at prune time — which is also when it
 * matters.
 */
class PruneTelescope extends Command
{
    protected $signature = 'observability:prune-telescope';

    protected $description = 'Prune Telescope entries past the retention window';

    public function handle(Settings $settings): int
    {
        return $this->call('telescope:prune', [
            '--hours' => $settings->integer(SettingKey::TelescopePruneHours),
        ]);
    }
}
