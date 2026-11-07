<?php

namespace Database\Factories;

use App\Enums\SettingKey;
use App\Models\Setting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Setting>
 */
class SettingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * Produces a real tunable at its default value, so a factory-made row is
     * always something App\Services\Settings can resolve. `key` is unique, hence
     * the unique modifier rather than a plain random pick.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $key = SettingKey::from(
            (string) fake()->unique()->randomElement(array_column(SettingKey::cases(), 'value')),
        );

        return [
            'key' => $key->value,
            'value' => $key->default(),
        ];
    }

    /**
     * Override one specific tunable.
     *
     * Named `override` rather than `for` because Factory::for() already means
     * "attach a belongsTo parent".
     */
    public function override(SettingKey $key, mixed $value): static
    {
        return $this->state(fn (array $attributes): array => [
            'key' => $key->value,
            'value' => $value,
        ]);
    }
}
