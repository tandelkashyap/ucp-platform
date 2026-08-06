<?php

namespace App\Http\Middleware;

use App\Models\AgentCredential;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Expects `Authorization: Bearer {key_id}.{secret}` and a scope parameter
 * per route, e.g. Route::get(...)->middleware('agent.auth:catalog').
 *
 * Deliberately does NOT check the credential's merchant_id against the
 * {merchant} route parameter here — that happens in each controller via
 * AuthorizesAgentCredential::assertCredentialMatches() instead, consistent
 * with how these controllers already scope everything else at the
 * controller level (e.g. CartController checking cart->merchant_id
 * directly) rather than splitting that logic across middleware and
 * controller.
 */
class AuthenticateAgent
{
    public function handle(Request $request, Closure $next, string $scope): Response
    {
        $token = $this->bearerToken($request);

        abort_if(! $token, 401, 'Missing agent credentials.');

        [$keyId, $secret] = array_pad(explode('.', $token, 2), 2, null);

        abort_if(! $keyId || ! $secret, 401, 'Malformed agent credentials.');

        $credential = AgentCredential::where('key_id', $keyId)->first();

        abort_if(! $credential || ! $credential->verify($secret), 401, 'Invalid agent credentials.');
        abort_if($credential->status !== 'active', 401, 'Agent credential has been revoked.');
        abort_if($credential->expires_at?->isPast(), 401, 'Agent credential has expired.');
        abort_unless($credential->hasScope($scope), 403, "Credential is not scoped for [{$scope}].");

        // Direct write on every authenticated request — fine at moderate
        // volume, but this is a DB write on the hot path, worth batching
        // or queuing if agent traffic gets heavy.
        $credential->update(['last_used_at' => now()]);

        $request->attributes->set('agent_credential', $credential);

        return $next($request);
    }

    private function bearerToken(Request $request): ?string
    {
        $header = $request->header('Authorization', '');

        return str_starts_with($header, 'Bearer ') ? substr($header, 7) : null;
    }
}
