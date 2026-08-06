<?php

namespace App\Services\Connectors;

use App\Contracts\CommerceConnector;
use App\Models\StoreConnection;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

/**
 * NOTE ON API SURFACES: BigCommerce's flow inverts Shopify's and
 * WooCommerce's. Cart, checkout, and order creation all live on one REST
 * Management API (v3, X-Auth-Token) — no separate storefront/session API
 * needed for that part, which is simpler than the other two. But "checkout"
 * here means *create an unpaid order*, not *submit payment and get an order
 * back*: billing address and shipping consignments are mandatory
 * sub-resources of the Checkout before an Order can be created from it, and
 * payment is then captured against that order as a distinct step — via a
 * token scoped to that one order, sent to a *different host*
 * (payments.bigcommerce.com) than everything else in this class.
 *
 * The payment-capture section below is the least certain part of this
 * connector — verify the exact endpoint/field names against BigCommerce's
 * current docs before relying on it. Same goes for the Orders resource
 * still being on `v2` — confirm that's still current.
 *
 * `credentials` needs: store_hash, client_id, access_token.
 */
class BigCommerceConnector implements CommerceConnector
{
    public function __construct(private readonly StoreConnection $connection)
    {
    }

    public function getCatalog(array $filters = []): Collection
    {
        $products = $this->restApi('GET', '/catalog/products', [
            'limit' => $filters['limit'] ?? 50,
        ])['data'] ?? [];

        return collect($products)->map(fn (array $product) => $this->normalizeProduct($product));
    }

    public function getProduct(string $externalId): ?array
    {
        try {
            $product = $this->restApi('GET', "/catalog/products/{$externalId}")['data'];

            return $this->normalizeProduct($product);
        } catch (RequestException $e) {
            if ($e->response->status() === 404) {
                return null;
            }

            throw $e;
        }
    }

    public function createCart(array $items): array
    {
        $response = $this->restApi('POST', '/carts', [
            'line_items' => $this->toLineItems($items),
        ]);

        return $this->normalizeCart($response['data']);
    }

    public function updateCart(string $externalCartId, array $items): array
    {
        // Same fetch-then-map-then-branch shape as WooCommerceConnector,
        // even though this is a plain REST API rather than a session-token
        // one: line items get their own id once added, distinct from the
        // product id, and you need that id to update or remove a line.
        $cart = $this->restApi('GET', "/carts/{$externalCartId}")['data'];
        $itemIdByProductId = collect($cart['line_items']['physical_items'] ?? [])
            ->pluck('id', 'product_id');

        foreach ($items as $item) {
            $productId = (int) $item['external_product_id'];
            $itemId = $itemIdByProductId->get($productId);

            $cart = $itemId
                ? $this->restApi('PUT', "/carts/{$externalCartId}/items/{$itemId}", [
                    'line_item' => ['product_id' => $productId, 'quantity' => $item['quantity']],
                ])['data']
                : $this->restApi('POST', "/carts/{$externalCartId}/items", [
                    'line_items' => [['product_id' => $productId, 'quantity' => $item['quantity']]],
                ])['data'];
        }

        return $this->normalizeCart($cart);
    }

    public function checkout(string $externalCartId, array $paymentToken, array $shippingAddress = []): array
    {
        // BigCommerce: checkout id === cart id, but it's a different
        // resource with its own required sub-resources before an order
        // can be created from it.
        $checkoutId = $externalCartId;

        if ($shippingAddress) {
            $this->restApi('POST', "/checkouts/{$checkoutId}/billing-address", $shippingAddress);
            $this->restApi('POST', "/checkouts/{$checkoutId}/consignments", [[
                'shipping_address' => $shippingAddress,
                'line_items' => $this->consignmentLineItems($checkoutId),
            ]]);
        }

        $order = $this->restApi('POST', "/checkouts/{$checkoutId}/orders")['data'];
        $orderId = $order['id'];

        // Order now exists but is unpaid ("Incomplete"). Payment is a
        // separate call, against a token scoped to this specific order.
        $paymentAccessToken = $this->restApi(
            'POST', "/orders/{$orderId}/payment-access-tokens"
        )['data']['id'];

        $payment = Http::withToken($paymentAccessToken, 'PAT')
            ->post("https://payments.bigcommerce.com/stores/{$this->storeHash()}/payments", [
                'payment' => ['instrument' => ['token' => $paymentToken['token']]],
                'order_id' => $orderId,
            ])
            ->throw()
            ->json();

        return [
            'external_order_id' => (string) $orderId,
            'status' => ($payment['status'] ?? null) === 'success' ? 'confirmed' : 'pending',
        ];
    }

    public function getOrderStatus(string $externalOrderId): array
    {
        $order = $this->restApi('GET', "/orders/{$externalOrderId}", [], version: 'v2');

        return [
            'external_order_id' => (string) $order['id'],
            'status' => $this->mapOrderStatus($order['status']),
            'total_cents' => $this->toCents((float) $order['total_inc_tax']),
            'currency' => $order['currency_code'] ?? 'USD',
        ];
    }

    public function handleWebhook(array $payload, array $headers): void
    {
        // Verify the `hash` field against your webhook secret before
        // trusting $payload — omitted for brevity.
        //
        // Two real differences from Shopify/WooCommerce: the event type
        // lives in the JSON body here (`scope`), not a header, and the
        // payload is a thin pointer — resource type + id — not the
        // resource itself. BigCommerce expects you to fetch current state
        // yourself, so handling a webhook here costs one extra API call,
        // not zero — conveniently, that's just this class's own
        // getProduct()/getOrderStatus().
        $scope = $payload['scope'] ?? '';
        $resourceId = $payload['data']['id'] ?? null;

        if (! $resourceId) {
            return;
        }

        match (true) {
            str_starts_with($scope, 'store/order/') => $this->dispatchOrderEvent((string) $resourceId),
            str_starts_with($scope, 'store/product/') => \App\Jobs\UpsertSyncedProduct::dispatch(
                $this->connection->merchant_id,
                $this->getProduct((string) $resourceId),
            ),
            default => null,
        };
    }

    private function dispatchOrderEvent(string $orderId): void
    {
        $order = $this->getOrderStatus($orderId);

        \App\Jobs\RecordOrderEvent::dispatch($this->connection->merchant_id, $order, $order['status']);
    }

    private function consignmentLineItems(string $checkoutId): array
    {
        $checkout = $this->restApi('GET', "/checkouts/{$checkoutId}")['data'];

        return collect($checkout['cart']['line_items']['physical_items'] ?? [])
            ->map(fn (array $item) => ['item_id' => $item['id'], 'quantity' => $item['quantity']])
            ->all();
    }

    private function toLineItems(array $items): array
    {
        return collect($items)->map(fn (array $item) => [
            'product_id' => (int) $item['external_product_id'],
            'quantity' => $item['quantity'],
        ])->all();
    }

    private function normalizeCart(array $cart): array
    {
        return [
            'external_cart_id' => $cart['id'],
            'currency' => $cart['currency']['code'] ?? 'USD',
            'total_cents' => $this->toCents($cart['cart_amount'] ?? 0),
        ];
    }

    private function normalizeProduct(array $product): array
    {
        return [
            'external_id' => (string) $product['id'],
            'title' => $product['name'],
            'description' => $product['description'] ?? null,
            // V3 Catalog returns price as a JSON number, not a string —
            // Shopify and WooCommerce's wc/v3 both give decimal strings.
            // Three connectors, three different money representations so far.
            'price_cents' => $this->toCents($product['price'] ?? 0),
            'inventory_quantity' => $product['inventory_level'] ?? 0,
            'raw_data' => $product,
        ];
    }

    private function mapOrderStatus(string $bigCommerceStatus): string
    {
        // The richest status vocabulary of the three platforms here —
        // collapse it down to the shared internal set.
        return match ($bigCommerceStatus) {
            'Incomplete', 'Pending', 'Awaiting Payment' => 'pending',
            'Awaiting Fulfillment', 'Awaiting Shipment' => 'confirmed',
            'Partially Shipped', 'Shipped', 'Awaiting Pickup' => 'shipped',
            'Completed' => 'delivered',
            'Refunded', 'Partially Refunded' => 'returned',
            'Cancelled', 'Declined' => 'cancelled',
            default => 'pending', // Manual Verification Required, Disputed, ...
        };
    }

    private function toCents(int|float $amount): int
    {
        return (int) round($amount * 100);
    }

    private function storeHash(): string
    {
        return $this->connection->credentials['store_hash'];
    }

    private function restApi(string $method, string $path, array $params = [], string $version = 'v3'): array
    {
        $credentials = $this->connection->credentials;

        $client = Http::withHeaders([
            'X-Auth-Token' => $credentials['access_token'],
            'X-Auth-Client' => $credentials['client_id'],
            'Accept' => 'application/json',
        ]);

        $url = "https://api.bigcommerce.com/stores/{$credentials['store_hash']}/{$version}{$path}";

        $response = match (strtoupper($method)) {
            'GET' => $client->get($url, $params),
            'PUT' => $client->put($url, $params),
            default => $client->post($url, $params),
        };

        return $response->throw()->json();
    }
}
