<?php

namespace App\Http\Requests\Admin;

use App\Enums\MessagingPlatform;
use App\Enums\SettingKey;
use App\Enums\SettingType;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

/**
 * One setting's new value, validated against the shape its key declares.
 *
 * The key comes from the route and the shape from the registry, so the
 * validation a value faces is the same rule the storage layer enforces —
 * there is no way to satisfy this request with a value `Settings::set()`
 * would reject, and no way to reach a key that is not in the registry.
 */
class UpdateSettingRequest extends FormRequest
{
    /**
     * Every admin route is already behind `EnsureUserIsAdmin`; this is the
     * re-check, so a revoked admin is refused even if a route is miswired.
     */
    public function authorize(): bool
    {
        return $this->user() instanceof User && $this->user()->is_admin;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        $key = $this->settingKey();

        if ($key === null) {
            // The route pattern only allows a registry value through in
            // practice; this branch is the backstop for one that doesn't.
            return [];
        }

        return match ($key->type()) {
            SettingType::Integer => $this->integerRules(),
            SettingType::Text => $this->textRules(),
            SettingType::Json => $this->jsonRules(),
            SettingType::Boolean => ['value' => ['required', 'boolean']],
        };
    }

    public function messages(): array
    {
        return [
            'value.*' => __('admin.settings.invalid_value'),
        ];
    }

    /**
     * A bad key reads as "no such setting" — the same uniformity the Mini
     * App surfaces apply to ids they do not own.
     */
    protected function prepareForValidation(): void
    {
        abort_if($this->settingKey() === null, 404);
    }

    private function settingKey(): ?SettingKey
    {
        return SettingKey::tryFrom((string) $this->route('setting'));
    }

    /**
     * Prices, rates and TTLs. Non-negative throughout: zero is a real value
     * (a disabled TTL, a free good), but a negative price is always a bug.
     *
     * @return array<string, list<string>>
     */
    private function integerRules(): array
    {
        return ['value' => ['required', 'integer', 'min:0', 'max:1000000']];
    }

    /**
     * The required channel and the alert ops platform. Neither is required to
     * start with `@` — a numeric `-100…` channel is a supported (if degraded)
     * configuration, and refusing it here would tell an admin their working
     * gate is invalid.
     *
     * @return array<string, list<string>>
     */
    private function textRules(): array
    {
        // The ops-chat platform is the one text setting that must be a
        // registry value, not free text: an unresolvable string would mean
        // alerting that looks configured and never sends.
        if ($this->settingKey() === SettingKey::AlertOpsPlatform) {
            return ['value' => ['required', 'string', 'in:'.implode(',', array_column(MessagingPlatform::cases(), 'value'))]];
        }

        return ['value' => ['required', 'string', 'max:64']];
    }

    /**
     * The coins package table. Each row is one purchasable package; `stars` is
     * what Telegram charges (XTR) and `coins` what we credit. `rial` is the
     * optional Bale-side price — absent or zero means the package is not sold
     * to Bale payers, which is why it is `sometimes` where its siblings are
     * `required`. Its cap is higher because Rial magnitudes are their own
     * order of magnitude.
     *
     * @return array<string, list<string>>
     */
    private function jsonRules(): array
    {
        return [
            'value' => ['required', 'array', 'min:1', 'max:20'],
            'value.*' => ['required', 'array'],
            'value.*.stars' => ['required', 'integer', 'min:1', 'max:1000000'],
            'value.*.coins' => ['required', 'integer', 'min:0', 'max:1000000'],
            'value.*.rial' => ['sometimes', 'integer', 'min:0', 'max:100000000'],
        ];
    }
}
