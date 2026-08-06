<?php

namespace App\Services\Connectors;

use App\Contracts\CommerceConnector;
use App\Models\StoreConnection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

/**
 * NOTE ON API SURFACES: real Shopify integrations split across two APIs —
 * the Admin API (catalog, orders, inventory; secret access token) and the
 * Storefront API (cart, checkout; a separate public token). This class
 * calls both through one `graphql()` helper for readability. In practice
 * `credentials` on the StoreConnection needs both tokens, and you'd likely
 * split this into ShopifyAdminClient/ShopifyStorefrontClient internally.
 */
class ShopifyConnector implements CommerceConnector
{
    private const API_VERSION = '2025-01';

    public function __construct(private readonly StoreConnection $connection)
    {
    }

    public function getCatalog(array $filters = []): Collection
    {
        $query = <<<'GQL'
            query Products($first: Int!) {
                products(first: $first) {
                    edges {
                        node {
                            id
                            title
                            description
                            variants(first: 1) {
                                edges { node { price inventoryQuantity } }
                            }
                        }
                    }
                }
            }
        GQL;

        $response = $this->graphql($query, ['first' => $filters['limit'] ?? 50]);

        return collect($response['data']['products']['edges'] ?? [])
            ->map(fn (array $edge) => $this->normalizeProduct($edge['node']));
    }

    public function getProduct(string $externalId): ?array
    {
        $query = <<<'GQL'
            query Product($id: ID!) {
                product(id: $id) {
                    id
                    title
                    description
                    variants(first: 1) {
                        edges { node { price inventoryQuantity } }
                    }
                }
            }
        GQL;

        $node = $this->graphql($query, ['id' => $externalId])['data']['product'] ?? null;

        return $node ? $this->normalizeProduct($node) : null;
    }

    public function createCart(array $items): array
    {
        $mutation = <<<'GQL'
            mutation CartCreate($lines: [CartLineInput!]!) {
                cartCreate(input: { lines: $lines }) {
                    cart { id cost { totalAmount { amount currencyCode } } }
                    userErrors { message }
                }
            }
        GQL;

        $response = $this->graphql($mutation, ['lines' => $this->toCartLines($items)]);

        return $this->normalizeCart($response['data']['cartCreate']['cart']);
    }

    public function updateCart(string $externalCartId, array $items): array
    {
        $mutation = <<<'GQL'
            mutation CartLinesUpdate($cartId: ID!, $lines: [CartLineUpdateInput!]!) {
                cartLinesUpdate(cartId: $cartId, lines: $lines) {
                    cart { id cost { totalAmount { amount currencyCode } } }
                    userErrors { message }
                }
            }
        GQL;

        $response = $this->graphql($mutation, [
            'cartId' => $externalCartId,
            'lines' => $this->toCartLines($items),
        ]);

        return $this->normalizeCart($response['data']['cartLinesUpdate']['cart']);
    }

    public function checkout(string $externalCartId, array $paymentToken, array $shippingAddress = []): array
    {
        // Real Shopify carts want delivery address attached earlier, via
        // cartBuyerIdentityUpdate on the cart itself (it affects shipping-
        // rate calculation before checkout), not passed in at checkout
        // time. $shippingAddress is accepted here for interface
        // compatibility but not yet wired in — see BigCommerceConnector
        // for a platform where this can't be deferred.
        //
        // The exact mutation shape depends on which payment handler was
        // negotiated (dev.shopify.shop_pay vs. a PSP-issued token) — this
        // shows the shape for a pre-acquired attempt token.
        $mutation = <<<'GQL'
            mutation CartSubmitForCompletion($cartId: ID!, $attemptToken: String!) {
                cartSubmitForCompletion(cartId: $cartId, attemptToken: $attemptToken) {
                    result {
                        ... on SubmitSuccess { order { id } }
                        ... on SubmitFailed { errors { message } }
                    }
                }
            }
        GQL;

        $response = $this->graphql($mutation, [
            'cartId' => $externalCartId,
            'attemptToken' => $paymentToken['token'],
        ]);

        $orderId = $response['data']['cartSubmitForCompletion']['result']['order']['id'] ?? null;

        return [
            'external_order_id' => $orderId,
            'status' => $orderId ? 'confirmed' : 'pending',
        ];
    }

    public function getOrderStatus(string $externalOrderId): array
    {
        $query = <<<'GQL'
            query Order($id: ID!) {
                order(id: $id) {
                    id
                    displayFulfillmentStatus
                    totalPriceSet { shopMoney { amount currencyCode } }
                }
            }
        GQL;

        $order = $this->graphql($query, ['id' => $externalOrderId])['data']['order'];

        return [
            'external_order_id' => $order['id'],
            'status' => $this->mapFulfillmentStatus($order['displayFulfillmentStatus']),
            'total_cents' => $this->toCents($order['totalPriceSet']['shopMoney']['amount']),
            'currency' => $order['totalPriceSet']['shopMoney']['currencyCode'],
        ];
    }

    public function handleWebhook(array $payload, array $headers): void
    {
        // Verify X-Shopify-Hmac-Sha256 against your webhook secret before
        // trusting $payload — omitted here for brevity, not optional in
        // production. Dispatch per-topic rather than processing inline so a
        // slow handler can't block Shopify's webhook delivery.
        //
        // Webhook payloads are REST-shaped (snake_case) even though the
        // rest of this connector talks to the GraphQL Admin API — Shopify
        // doesn't unify the two, so they get their own normalizers below
        // instead of reusing normalizeProduct().
        $topic = $headers['X-Shopify-Topic'] ?? '';

        match (true) {
            str_starts_with($topic, 'orders/') => $this->dispatchOrderEvent($payload),
            str_starts_with($topic, 'products/') => \App\Jobs\UpsertSyncedProduct::dispatch(
                $this->connection->merchant_id,
                $this->normalizeWebhookProduct($payload),
            ),
            default => null,
        };
    }

    private function dispatchOrderEvent(array $payload): void
    {
        $order = $this->normalizeWebhookOrder($payload);

        \App\Jobs\RecordOrderEvent::dispatch($this->connection->merchant_id, $order, $order['status']);
    }

    private function toCartLines(array $items): array
    {
        return collect($items)->map(fn (array $item) => [
            'merchandiseId' => $item['external_product_id'],
            'quantity' => $item['quantity'],
        ])->all();
    }

    private function normalizeCart(array $cart): array
    {
        return [
            'external_cart_id' => $cart['id'],
            'currency' => $cart['cost']['totalAmount']['currencyCode'],
            'total_cents' => $this->toCents($cart['cost']['totalAmount']['amount']),
        ];
    }

    private function normalizeProduct(array $node): array
    {
        $variant = $node['variants']['edges'][0]['node'] ?? ['price' => 0, 'inventoryQuantity' => 0];

        return [
            'external_id' => $node['id'],
            'title' => $node['title'],
            'description' => $node['description'] ?? null,
            'price_cents' => $this->toCents($variant['price']),
            'inventory_quantity' => $variant['inventoryQuantity'],
            'raw_data' => $node,
        ];
    }

    /**
     * Webhook payloads are REST-shaped — different field names/casing than
     * the GraphQL responses normalizeProduct() handles above.
     */
    private function normalizeWebhookProduct(array $payload): array
    {
        $variant = $payload['variants'][0] ?? ['price' => 0, 'inventory_quantity' => 0];

        return [
            'external_id' => (string) $payload['id'],
            'title' => $payload['title'],
            'description' => $payload['body_html'] ?? null,
            'price_cents' => $this->toCents($variant['price']),
            'inventory_quantity' => $variant['inventory_quantity'],
            'raw_data' => $payload,
        ];
    }

    private function normalizeWebhookOrder(array $payload): array
    {
        return [
            'external_order_id' => (string) $payload['id'],
            'status' => $this->mapFulfillmentStatus($payload['fulfillment_status'] ?? ''),
            'total_cents' => $this->toCents($payload['total_price'] ?? '0'),
            'currency' => $payload['currency'] ?? 'USD',
        ];
    }

    private function mapFulfillmentStatus(string $shopifyStatus): string
    {
        return match ($shopifyStatus) {
            'FULFILLED' => 'shipped',
            'PARTIALLY_FULFILLED' => 'confirmed',
            default => 'pending',
        };
    }

    private function toCents(string|float $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    private function graphql(string $query, array $variables = []): array
    {
        $credentials = $this->connection->credentials;

        return Http::withHeaders([
                'X-Shopify-Access-Token' => $credentials['access_token'],
                'Content-Type' => 'application/json',
            ])
            ->post(
                "https://{$credentials['shop_domain']}/admin/api/".self::API_VERSION.'/graphql.json',
                ['query' => $query, 'variables' => $variables]
            )
            ->throw()
            ->json();
    }
}
