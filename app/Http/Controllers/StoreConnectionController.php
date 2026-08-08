<?php

namespace App\Http\Controllers;

use App\Http\Requests\ConnectStoreRequest;
use App\Jobs\TestStoreConnection;
use App\Models\Merchant;
use App\Models\StoreConnection;

class StoreConnectionController extends Controller
{
    public function index(Merchant $merchant)
    {
        $this->authorize('view', $merchant);

        return response()->json(
            $merchant->storeConnections()
                ->get(['id', 'platform', 'external_store_identifier', 'status', 'last_error', 'last_synced_at'])
        );
    }

    public function store(ConnectStoreRequest $request, Merchant $merchant)
    {
        $connection = $merchant->storeConnections()->create([
            'platform' => $request->validated('platform'),
            'external_store_identifier' => $this->identifierFor($request),
            'credentials' => $request->validated('credentials'),
            'status' => 'connecting',
        ]);

        TestStoreConnection::dispatch($connection);

        return response()->json($connection->only('id', 'platform', 'status'), 201);
    }

    public function destroy(Merchant $merchant, StoreConnection $connection)
    {
        $this->authorize('update', $merchant);
        abort_unless($connection->merchant_id === $merchant->id, 404);

        // Soft — keeps sync history and orders attached to a real row
        // rather than cascading a delete through carts/orders.
        $connection->update(['status' => 'disconnected']);

        return response()->noContent();
    }

    /**
     * Which credential field identifies the storefront differs per
     * platform — same idea as ConnectStoreRequest's rules, kept next to
     * each other on purpose since they need to change together.
     */
    private function identifierFor(ConnectStoreRequest $request): string
    {
        return match ($request->validated('platform')) {
            'shopify' => $request->validated('credentials.shop_domain'),
            'woocommerce' => $request->validated('credentials.site_url'),
            'bigcommerce' => $request->validated('credentials.store_hash'),
            'magento' => $request->validated('credentials.base_url'),
        };
    }
}
