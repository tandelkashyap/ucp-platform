<?php

namespace App\Http\Controllers\Ucp;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Ucp\Concerns\AuthorizesAgentCredential;
use App\Jobs\RecordOrderEvent;
use App\Models\Cart;
use App\Models\Merchant;
use App\Services\ConnectorManager;
use Illuminate\Http\Request;

/**
 * Requires a valid agent_credentials token scoped for 'checkout' — see
 * AuthenticateAgent and the route definition in routes/api.php. That
 * covers authentication and coarse scope; it does not replace ordinary
 * payment-side scrutiny (fraud checks, velocity limits) that belongs
 * downstream of this, e.g. with the PSP itself.
 */
class CheckoutController extends Controller
{
    use AuthorizesAgentCredential;

    public function store(Request $request, Merchant $merchant, Cart $cart, ConnectorManager $connectors)
    {
        abort_unless($merchant->hasCapability('checkout'), 404);
        abort_unless($cart->merchant_id === $merchant->id, 404);
        $this->assertCredentialMatches($request, $merchant);
        abort_if($cart->status !== 'open', 409, 'Cart is no longer open.');
        abort_unless($cart->external_cart_id, 422, 'Cart was never created on the underlying platform.');

        $validated = $request->validate([
            // handler_id is required here for one consistent contract
            // across all three connectors, even though only
            // WooCommerceConnector::gatewayFor() actually branches on it
            // today — Shopify and BigCommerce currently ignore it.
            'payment_token.token' => ['required', 'string'],
            'payment_token.handler_id' => ['required', 'string'],
            'shipping_address' => ['sometimes', 'array'],
        ]);

        $connector = $connectors->for($merchant->activeStoreConnection());

        $result = $connector->checkout(
            $cart->external_cart_id,
            $validated['payment_token'],
            $validated['shipping_address'] ?? [],
        );

        if (! $result['external_order_id']) {
            // Submitted but not yet confirmed (e.g. an async payment
            // capture) — a real implementation would poll or wait on a
            // webhook here rather than leaving it at this.
            return response()->json(['status' => $result['status'] ?? 'pending'], 202);
        }

        // Reuses the exact same job the webhook handlers and bulk sync
        // dispatch into — a third calling context (direct and synchronous,
        // right here in the request) for the same shared logic.
        RecordOrderEvent::dispatchSync($merchant->id, [
            'external_order_id' => $result['external_order_id'],
            'status' => $result['status'],
            'total_cents' => $cart->totalCents(),
            'currency' => $cart->currency,
            'cart_id' => $cart->id,
            // Header name illustrative — see the same note in CartController.
            'agent_platform' => $request->header('UCP-Agent-Id'),
        ], 'placed');

        $cart->update(['status' => 'checked_out']);

        $order = $merchant->orders()->where('external_order_id', $result['external_order_id'])->firstOrFail();

        return response()->json([
            'order_id' => $order->external_order_id,
            'status' => $order->status,
        ], 201);
    }
}
