<?php

namespace App\Http\Middleware\Api;

use App\Models\User;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Passport\AccessToken;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\ResourceServer;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Component\HttpFoundation\Response;

/**
 * docs/contracts/api-contract.md §1.1 — both OAuth2 client-credentials tokens
 * (n8n/partners) and personal access tokens must authenticate `/api/v1/*`.
 * Passport's own `auth:api` guard (TokenGuard) resolves `$request->user()` to
 * null for a pure client-credentials token (no oauth_user_id) — see
 * TokenGuard::authenticateViaBearerToken(), which explicitly bails out when
 * `oauth_user_id === oauth_client_id`. That would make `auth:api` 401 every
 * n8n call. So this middleware validates the bearer token directly against
 * the resource server (same mechanism Passport's own ValidateToken/
 * EnsureClientIsResourceOwner middleware use) and, for a client-credentials
 * token, falls back to the client's `owner` — "a client also carries a user
 * identity (its owner) — record-level ACL applies exactly as it does in the
 * interface" (contract §1.1).
 */
final class AuthenticateApiToken
{
    public function __construct(private readonly ResourceServer $server) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $psrRequest = (new PsrHttpFactory)->createRequest($request);

        try {
            $psrRequest = $this->server->validateAuthenticatedRequest($psrRequest);
        } catch (OAuthServerException) {
            throw new AuthenticationException('Unauthenticated.', ['api']);
        }

        $token = AccessToken::fromPsrRequest($psrRequest);
        $request->attributes->set('oauth_access_token', $token);

        // Resolved once here and reused by ApiThrottle (§1.6 — per-client rate limit)
        // so a request never looks the client up from the database twice.
        $client = app(ClientRepository::class)->findActive($token->oauth_client_id);
        $request->attributes->set('oauth_client', $client);

        $actor = $this->resolveActor($token, $client);
        if ($actor !== null) {
            Auth::guard('api')->setUser($actor);
            $request->setUserResolver(fn (): User => $actor);
        }

        Auth::shouldUse('api');

        return $next($request);
    }

    /**
     * @param  AccessToken<mixed>  $token
     */
    private function resolveActor(AccessToken $token, ?Client $client): ?User
    {
        $oauthUserId = $token->oauth_user_id;
        $oauthClientId = $token->oauth_client_id;

        $user = ! empty($oauthUserId) && $oauthUserId !== $oauthClientId
            ? User::find($oauthUserId)
            : ($client?->owner instanceof User ? $client->owner : null);

        return $user?->withAccessToken($token);
    }
}
