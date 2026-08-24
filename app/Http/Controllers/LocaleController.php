<?php

namespace App\Http\Controllers;

use App\Http\Requests\LocaleUpdateRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Cookie;

class LocaleController extends Controller
{
    /**
     * Remember the visitor's language choice.
     *
     * Kept in a cookie rather than on the user, because most visitors have no
     * user row — bot users authenticate by Telegram identity. Once the User
     * model gains its `locale` field (Domain Task 1) a stored preference will
     * win over this cookie; see the SetLocale middleware.
     */
    public function update(LocaleUpdateRequest $request): RedirectResponse
    {
        return back()->withCookie(
            Cookie::forever(Config::string('localization.cookie'), $request->locale()),
        );
    }
}
