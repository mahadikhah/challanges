<?php

namespace App\Http\Requests\Admin;

use App\Enums\AiLimitPeriod;
use App\Services\Ai\AiDriverCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * One shape for creating and updating a provider account. The driver arrives
 * with the request, so the config fields are validated dynamically against
 * the catalog — the same array that renders the form and decides
 * `isConfigured()`, which means a field cannot be required by one and
 * optional to the other.
 *
 * Secret fields are deliberately nullable at this layer: whether a blank
 * means "keep the stored credential" is `SaveAiProviderAccount`'s call, not
 * validation's.
 */
class SaveAiProviderAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $driver = AiDriverCatalog::knows((string) $this->input('driver'))
            ? (string) $this->input('driver')
            : '';

        $configRules = [];

        foreach (AiDriverCatalog::knownKeys($driver) as $key) {
            $configRules["config.{$key}"] = in_array($key, AiDriverCatalog::requiredKeys($driver), true)
                ? ['required', 'string', 'max:500']
                : ['nullable', 'string', 'max:500'];
        }

        return [
            'name' => ['required', 'string', 'max:100'],
            'ai_capability_id' => ['required', 'integer', Rule::exists('ai_capabilities', 'id')],
            'driver' => ['required', 'string', Rule::in(AiDriverCatalog::drivers())],
            'model' => ['required', 'string', 'max:200'],
            'is_active' => ['required', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'config' => ['nullable', 'array'],
            ...$configRules,
            'input_token_limit' => ['nullable', 'integer', 'min:0'],
            'output_token_limit' => ['nullable', 'integer', 'min:0'],
            'total_token_limit' => ['nullable', 'integer', 'min:0'],
            'limit_period' => ['required', Rule::enum(AiLimitPeriod::class)],
            'limit_timezone' => ['required', 'string', 'timezone'],
            'input_token_price_per_million' => ['nullable', 'integer', 'min:0'],
            'output_token_price_per_million' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
