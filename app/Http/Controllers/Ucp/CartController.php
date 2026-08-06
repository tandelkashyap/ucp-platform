<?php

namespace App\Http\Controllers\Ucp;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Ucp\Concerns\AuthorizesAgentCredential;
use App\Models\Cart;
use App\Models\Merchant;
use App\Services\ConnectorManager;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class CartController extends Controller
{
    use AuthorizesAgentCredential;

    public function store(Request $request, Merchant $merchant, ConnectorManager $connectors)
    {
        abort_unless($merchant->hasCapability('cart'), 404);
        $this->assertCredentialMatches($request, $merchant);

        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'string'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
        ]);

        $products = $this->resolveProducts($merchant, $validated['items']);

        $cart = $merchant->carts()->create([
            'status' => 'open',
            'currency' => $products->first()->currency,
            // Header name illustrative — swap for whatever the current UCP
            // spec calls agent/session identification.
            'agent_session_id' => $request->header('UCP-Agent-Session'),
        ]);

        $this->syncItems($cart, $validated['items'], $products);

        // Also create the cart on the real platform, not just locally, so
        // it's a live cart the merchant's own systems (inventory holds,
        // abandoned-cart flows) know about too.
        $connector = $connectors->for($merchant->activeStoreConnection());
        $external = $connector->createCart($this->toConnectorItems($validated['items']));

        $cart->update([
            'external_cart_id' => $external['external_cart_id'],
            'currency' => $external['currency'],
        ]);

        return response()->json($this->present($cart), 201);
    }

    public function show(Request $request, Merchant $merchant, Cart $cart)
    {
        abort_unless($cart->merchant_id === $merchant->id, 404);
        $this->assertCredentialMatches($request, $merchant);

        return response()->json($this->present($cart));
    }

    public function update(Request $request, Merchant $merchant, Cart $cart, ConnectorManager $connectors)
    {
        abort_unless($merchant->hasCapability('cart'), 404);
        abort_unless($cart->merchant_id === $merchant->id, 404);
        $this->assertCredentialMatches($request, $merchant);
        abort_if($cart->status !== 'open', 409, 'Cart is no longer open.');

        // Quantity changes and additions only in this pass — removing a
        // line item entirely is a real gap, called out in the README.
        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'string'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
        ]);

        $products = $this->resolveProducts($merchant, $validated['items']);
        $this->syncItems($cart, $validated['items'], $products);

        $connector = $connectors->for($merchant->activeStoreConnection());
        $external = $connector->updateCart($cart->external_cart_id, $this->toConnectorItems($validated['items']));

        $cart->update(['currency' => $external['currency']]);

        return response()->json($this->present($cart));
    }

    /**
     * @param array<int, array{product_id: string, quantity: int}> $items
     */
    private function resolveProducts(Merchant $merchant, array $items): Collection
    {
        $externalIds = collect($items)->pluck('product_id');

        $products = $merchant->products()->whereIn('external_id', $externalIds)->get()->keyBy('external_id');

        $missing = $externalIds->diff($products->keys());
        abort_if($missing->isNotEmpty(), 422, "Unknown product id(s): {$missing->implode(', ')}");

        return $products;
    }

    private function syncItems(Cart $cart, array $items, Collection $products): void
    {
        foreach ($items as $item) {
            $product = $products[$item['product_id']];

            // Snapshot price now — see the same reasoning in the cart_items
            // migration. A live re-price on every update would mean an
            // agent's cart total can drift out from under it mid-session.
            $cart->items()->updateOrCreate(
                ['product_id' => $product->id],
                ['quantity' => $item['quantity'], 'unit_price_cents' => $product->price_cents],
            );
        }
    }

    private function toConnectorItems(array $items): array
    {
        return collect($items)->map(fn (array $item) => [
            'external_product_id' => $item['product_id'],
            'quantity' => $item['quantity'],
        ])->all();
    }

    private function present(Cart $cart): array
    {
        $cart->loadMissing('items.product');

        return [
            'id' => $cart->id,
            'status' => $cart->status,
            'currency' => $cart->currency,
            'total' => $cart->totalCents() / 100,
            'items' => $cart->items->map(fn ($item) => [
                'product_id' => $item->product->external_id,
                'title' => $item->product->title,
                'quantity' => $item->quantity,
                'unit_price' => $item->unit_price_cents / 100,
            ]),
        ];
    }
}
