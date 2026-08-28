<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The admin gate.
 *
 * Runs on every admin route, every request — the re-check CLAUDE.md demands
 * for authorization-sensitive surfaces, so revoking `is_admin` takes effect
 * on the next click, not the next login. Guests never reach it: `auth`
 * redirects them to the Fortify login first, which is also why this
 * middleware can assume a user exists.
 */
class EnsureUserIsAdmin
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless($user instanceof User && $user->is_admin, 403);

        return $next($request);
    }
}
