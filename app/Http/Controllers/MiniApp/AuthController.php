<?php

namespace App\Http\Controllers\MiniApp;

use App\Actions\MiniApp\AuthenticateMiniAppUser;
use App\Exceptions\InvalidInitDataException;
use App\Http\Controllers\Controller;
use App\Http\Requests\MiniApp\AuthenticateRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * The Mini App's only unauthenticated route: exchange initData for a token.
 *
 * Refusals are uniform on purpose. A rejected exchange returns one message
 * with no reason attached — "tampered" versus "outdated" is a detail for the
 * log, where it is useful, not for the reply, where it would be reconnaissance
 * for whoever is sending the forgeries.
 */
class AuthController extends Controller
{
    public function __construct(private readonly AuthenticateMiniAppUser $authenticate) {}

    public function store(AuthenticateRequest $request): JsonResponse
    {
        try {
            $session = $this->authenticate->handle($request->validated('init_data'));
        } catch (InvalidInitDataException $rejected) {
            Log::warning('A Mini App authentication was rejected.', [
                'reason' => $rejected->getMessage(),
            ]);

            return response()->json(
                ['message' => 'The Mini App identity could not be verified.'],
                401,
            );
        }

        return response()->json([
            'token' => $session->token,
            'token_type' => 'Bearer',
            'expires_at' => $session->expiresAt->toIso8601String(),
            'user' => [
                'id' => $session->user->getKey(),
                'first_name' => $session->user->first_name,
                'username' => $session->user->telegram_username,
                'locale' => $session->user->locale,
            ],
        ]);
    }
}
