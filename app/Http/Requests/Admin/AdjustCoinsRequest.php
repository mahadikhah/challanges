<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

/**
 * One manual coin adjustment: a magnitude and a direction, nothing else.
 *
 * The magnitude is a positive integer and the direction is one of two literal
 * strings, because the ledger takes a magnitude and derives the sign from the
 * reason — `AdjustUserCoins` maps the direction onto `AdminCredit` /
 * `AdminDebit`, so this request shape is the whole vocabulary a caller has.
 * There is deliberately no free-text note field: the ledger row's reference is
 * the admin who acted, which is the part of "why" the schema can carry.
 */
class AdjustCoinsRequest extends FormRequest
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
        return [
            'amount' => ['required', 'integer', 'min:1', 'max:1000000'],
            'direction' => ['required', 'string', 'in:credit,debit'],
        ];
    }

    public function messages(): array
    {
        return [
            'amount.*' => __('admin.users.invalid_amount'),
            'direction.*' => __('admin.users.invalid_direction'),
        ];
    }
}
