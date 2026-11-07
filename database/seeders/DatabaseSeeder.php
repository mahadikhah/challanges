<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * There is deliberately no settings seeder: a `settings` row means "an admin
     * overrode this", so seeding one per SettingKey would freeze today's shipped
     * defaults into every install and stop a later release from ever improving
     * them. Defaults live in the enum; see App\Services\Settings.
     */
    public function run(): void
    {
        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);
    }
}
