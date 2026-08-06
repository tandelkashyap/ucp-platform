<?php

namespace App\Http\Controllers\Ucp\Concerns;

use App\Models\AgentCredential;
use App\Models\Merchant;
use Illuminate\Http\Request;

trait AuthorizesAgentCredential
{
    /**
     * Confirms the credential AuthenticateAgent already verified actually
     * belongs to the merchant this request is acting on — a valid
     * credential for merchant A must not work against merchant B's routes.
     */
    private function assertCredentialMatches(Request $request, Merchant $merchant): AgentCredential
    {
        /** @var AgentCredential $credential */
        $credential = $request->attributes->get('agent_credential');

        abort_if($credential->merchant_id !== $merchant->id, 403, 'Credential not valid for this merchant.');

        return $credential;
    }
}
