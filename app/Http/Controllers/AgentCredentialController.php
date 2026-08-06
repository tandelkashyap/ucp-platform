<?php

namespace App\Http\Controllers;

use App\Models\AgentCredential;
use App\Models\Merchant;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AgentCredentialController extends Controller
{
    public function index(Merchant $merchant)
    {
        $this->authorize('view', $merchant);

        // Never the secret half — only key_id, which is safe to display.
        return response()->json(
            $merchant->agentCredentials()
                ->get(['id', 'agent_platform', 'key_id', 'scopes', 'status', 'last_used_at', 'expires_at'])
        );
    }

    public function store(Request $request, Merchant $merchant)
    {
        $this->authorize('update', $merchant);

        $validated = $request->validate([
            'agent_platform' => ['required', 'string', 'max:255'],
            'scopes' => ['required', 'array', 'min:1'],
            // Only capabilities with a real enforcement point today — see
            // the middleware entries in routes/api.php. No point letting
            // someone request a scope for e.g. identity_linking, which
            // doesn't gate anything yet.
            'scopes.*' => [Rule::in(['catalog', 'cart', 'checkout'])],
            'expires_at' => ['sometimes', 'date', 'after:now'],
        ]);

        $result = AgentCredential::generate($merchant, $validated['agent_platform'], $validated['scopes']);

        if (isset($validated['expires_at'])) {
            $result['credential']->update(['expires_at' => $validated['expires_at']]);
        }

        // The only response that will ever contain the plaintext token —
        // it isn't retrievable again after this, by design.
        return response()->json([
            'id' => $result['credential']->id,
            'token' => $result['plaintext'],
            'scopes' => $result['credential']->scopes,
        ], 201);
    }

    public function destroy(Merchant $merchant, AgentCredential $credential)
    {
        $this->authorize('update', $merchant);
        abort_unless($credential->merchant_id === $merchant->id, 404);

        $credential->update(['status' => 'revoked']);

        return response()->noContent();
    }
}
