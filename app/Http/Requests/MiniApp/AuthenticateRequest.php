<?php

namespace App\Http\Requests\MiniApp;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The Mini App's token exchange: just the raw `Telegram.WebApp.initData` string.
 *
 * The field is deliberately unvalidated beyond shape — every property that
 * matters about it is a cryptographic claim, checked by `InitDataVerifier`
 * against the bot token, not a format a rule here could vouch for.
 */
class AuthenticateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            // initData is a URL-encoded query string of a few hundred bytes;
            // the bound is generous but exists so a stray body cannot be
            // parsed as an unbounded payload.
            'init_data' => ['required', 'string', 'max:8192'],
        ];
    }
}
