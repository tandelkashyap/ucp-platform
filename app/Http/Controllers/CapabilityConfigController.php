<?php

namespace App\Http\Controllers;

use App\Models\CapabilityConfig;
use App\Models\Merchant;
use Illuminate\Http\Request;

class CapabilityConfigController extends Controller
{
    public function index(Merchant $merchant)
    {
        $this->authorize('view', $merchant);

        return response()->json($merchant->capabilityConfigs);
    }

    /**
     * Also where a merchant sets platform-specific config for a capability —
     * e.g. WooCommerceConnector::gatewayFor() reads config.gateway_mapping
     * from the payment_token_exchange row created here.
     */
    public function update(Request $request, Merchant $merchant, CapabilityConfig $capabilityConfig)
    {
        $this->authorize('update', $merchant);
        abort_unless($capabilityConfig->merchant_id === $merchant->id, 404);

        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'config' => ['sometimes', 'array'],
        ]);

        $capabilityConfig->update($validated);

        return response()->json($capabilityConfig);
    }
}
