<?php

namespace App\Http\Requests;

use App\Services\Localization;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LocaleUpdateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * The allowlist comes from config, so an arbitrary value can never reach
     * `App::setLocale()` or the translation loader's file paths.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(Localization $localization): array
    {
        return [
            'locale' => ['required', 'string', Rule::in($localization->codes())],
        ];
    }

    /**
     * The validated locale, guaranteed to be one this platform supports.
     */
    public function locale(): string
    {
        return (string) $this->validated('locale');
    }
}
