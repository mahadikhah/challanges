<?php

namespace App\Services;

use App\Enums\SettingKey;
use App\Enums\SettingType;
use App\Models\Setting;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use LogicException;

/**
 * The one way to read a tunable value.
 *
 * A `settings` row is an override; the SettingKey case carries the default. So
 * `get()` answers correctly against an empty table, which means a forgotten
 * seeder can never take pricing down and a newly added tunable needs no
 * backfill migration. The same contract holds one step earlier — against a
 * schema that has not been migrated yet — because reading a tunable happens
 * during `key:generate`, the first queries of `migrate --force`, and exception
 * reporting on a fresh database, none of which may abort. See overrides().
 *
 * Reads go through a single cache entry holding every override, because the
 * cache store is the `database` driver (no Redis on the production host) and
 * per-key entries would turn one webhook into a dozen SELECTs. That entry is
 * also the reason writes must go through `set()`/`forget()` — see `flush()`.
 *
 * Registered as a singleton in AppServiceProvider so the per-request memo below
 * is shared, and so `set()` cannot leave a stale copy behind in a second
 * instance.
 */
class Settings
{
    /**
     * The cache entry holding every override, keyed `key => value`.
     */
    public const string CACHE_KEY = 'settings.overrides';

    /**
     * Per-request memo of that entry, so repeated reads cost nothing.
     *
     * @var array<string, mixed>|null
     */
    private ?array $overrides = null;

    /**
     * The effective value of a tunable: the admin override if one exists,
     * otherwise the registry default.
     */
    public function get(SettingKey $key): mixed
    {
        $overrides = $this->overrides();

        return array_key_exists($key->value, $overrides)
            ? $overrides[$key->value]
            : $key->default();
    }

    /**
     * A wrong-typed value in the database (a hand-edited row, say) falls back to
     * the registry default rather than throwing: a corrupt setting must not be
     * able to take check-ins down.
     *
     * @throws LogicException when the key is not a text setting
     */
    public function string(SettingKey $key): string
    {
        $value = $this->typed($key, SettingType::Text);

        if (is_string($value)) {
            return $value;
        }

        $default = $key->default();

        return is_string($default) ? $default : '';
    }

    /**
     * @throws LogicException when the key is not an integer setting
     */
    public function integer(SettingKey $key): int
    {
        $value = $this->typed($key, SettingType::Integer);

        if (is_int($value)) {
            return $value;
        }

        $default = $key->default();

        return is_int($default) ? $default : 0;
    }

    /**
     * @throws LogicException when the key is not a boolean setting
     */
    public function boolean(SettingKey $key): bool
    {
        $value = $this->typed($key, SettingType::Boolean);

        if (is_bool($value)) {
            return $value;
        }

        $default = $key->default();

        return is_bool($default) && $default;
    }

    /**
     * @return array<array-key, mixed>
     *
     * @throws LogicException when the key is not a json setting
     */
    public function array(SettingKey $key): array
    {
        $value = $this->typed($key, SettingType::Json);

        if (is_array($value)) {
            return $value;
        }

        $default = $key->default();

        return is_array($default) ? $default : [];
    }

    /**
     * Every tunable's effective value, for the admin panel and for debugging.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $values = [];

        foreach (SettingKey::cases() as $key) {
            $values[$key->value] = $this->get($key);
        }

        return $values;
    }

    /**
     * Whether an admin override exists for this tunable — the registry
     * default is what applies otherwise.
     */
    public function isOverridden(SettingKey $key): bool
    {
        return array_key_exists($key->value, $this->overrides());
    }

    /**
     * Override a tunable.
     *
     * Rejects a value of the wrong shape rather than coercing it: these are
     * prices and rates, and a silently-cast value is worse than a loud failure.
     *
     * @throws InvalidArgumentException when the value does not match the key's declared type
     */
    public function set(SettingKey $key, mixed $value): Setting
    {
        $type = $key->type();

        if (! $type->matches($value)) {
            throw new InvalidArgumentException(
                sprintf('Setting [%s] expects %s.', $key->value, $type->describe()),
            );
        }

        $setting = Setting::query()->updateOrCreate(
            ['key' => $key->value],
            ['value' => $value],
        );

        $this->flush();

        return $setting;
    }

    /**
     * Drop an override, reverting the tunable to its registry default.
     */
    public function forget(SettingKey $key): void
    {
        Setting::query()->where('key', $key->value)->delete();

        $this->flush();
    }

    /**
     * Discard the cached overrides.
     *
     * Anything that writes to the `settings` table outside `set()`/`forget()` —
     * a seeder, a migration, a manual fix in production — must call this, or
     * readers keep serving the old value until the cache is cleared.
     */
    public function flush(): void
    {
        $this->overrides = null;

        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @return array<string, mixed>
     */
    private function overrides(): array
    {
        if ($this->overrides !== null) {
            return $this->overrides;
        }

        try {
            return $this->overrides = Cache::rememberForever(
                self::CACHE_KEY,
                fn (): array => $this->load(),
            );
        } catch (QueryException $e) {
            if (! $this->isMissingTable($e)) {
                throw $e;
            }

            // The schema has not been migrated yet. The answer is "no
            // overrides": the registry default each key carries IS the
            // fallback. Memoised in memory only — the memo dies with the
            // process, so the `migrate` run that creates these tables is not
            // left serving defaults afterwards, and nothing is written through
            // rememberForever, whose entry WOULD outlive the migration and
            // hide admin overrides until a cache flush.
            return $this->overrides = [];
        }
    }

    /**
     * MySQL reports a missing table as 42S02/1146, SQLite as HY000 with the
     * message below — the test suite runs on both, so both spellings are
     * load-bearing.
     */
    private function isMissingTable(QueryException $e): bool
    {
        return $e->getCode() === '42S02'
            || str_contains($e->getMessage(), 'no such table');
    }

    /**
     * Read the override rows, ignoring any key no longer in the registry — a
     * removed tunable leaves its row behind, and that row must not resurface as
     * a value nobody reads.
     *
     * @return array<string, mixed>
     */
    private function load(): array
    {
        $overrides = [];
        $known = array_column(SettingKey::cases(), 'value');

        foreach (Setting::query()->whereIn('key', $known)->get() as $setting) {
            $overrides[$setting->key] = $setting->value;
        }

        return $overrides;
    }

    /**
     * Fetch a value, asserting the call site asked for the right type.
     *
     * @throws LogicException when the accessor does not match the key's declared type
     */
    private function typed(SettingKey $key, SettingType $expected): mixed
    {
        if ($key->type() !== $expected) {
            throw new LogicException(
                sprintf(
                    'Setting [%s] is %s, not %s.',
                    $key->value,
                    $key->type()->describe(),
                    $expected->describe(),
                ),
            );
        }

        return $this->get($key);
    }
}
