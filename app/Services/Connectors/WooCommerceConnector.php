<?php

namespace App\Services\Connectors;

use App\Contracts\CommerceConnector;
use App\Models\StoreConnection;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * NOTE ON API SURFACES: WooCommerce splits across two REST APIs, not one.
 * `wc/v3` (Consumer Key/Secret auth) is the admin/management API — products,
 * orders, catalog. It has no cart or checkout endpoints; core WooCommerce
 * was never built with a headless storefront in mind. Cart/checkout instead
 * go through the newer `wc/store/v1` Store API, which is session-based
 * (a `Cart-Token` header, not an ID you pass around) and unauthenticated by
 * design, since it's meant for anonymous storefront visitors.
 *
 * `credentials` on the StoreConnection needs: site_url, consumer_key,
 * consumer_secret.
 */
class WooCommerceConnector implements CommerceConnector
{
    public function __construct(private readonly StoreConnection $connection)
    {
    }

    public function getCatalog(array $filters = []): Collection
    {
        $products = $this->restApi('GET', '/products', [
            'per_page' => $filters['limit'] ?? 50,
        ]);

        return collect($products)->map(fn (array $product) => $this->normalizeProduct($product));
    }

    public function getProduct(string $externalId): ?array
    {
        try {
            return $this->normalizeProduct($this->restApi('GET', "/products/{$externalId}"));
        } catch (RequestException $e) {
            if ($e->response->status() === 404) {
                return null;
            }

            throw $e;
        }
    }

    public function createCart(array $items): array
    {
        // Store API adds one line at a time — there's no "create with N
        // lines" mutation the way Shopify's cartCreate has. The first call
        // establishes the session; its response carries the Cart-Token
        // every later call must send back to stay on the same cart.
        $cart = null;
        $cartToken = null;

        foreach ($items as $item) {
            [$cart, $cartToken] = $this->storeApi('POST', '/cart/add-item', [
                'id' => (int) $item['external_product_id'],
                'quantity' => $item['quantity'],
            ], $cartToken);
        }

        return $this->normalizeCart($cart ?? [], $cartToken);
    }

    public function updateCart(string $externalCartId, array $items): array
    {
        $cartToken = $externalCartId;

        // Store API updates/removes are keyed by a per-line hash ("key"),
        // not the product id — unlike Shopify, where the merchandise id
        // itself is what you pass to cartLinesUpdate. Look the cart up
        // first to map product id -> line key.
        [$current, $cartToken] = $this->storeApi('GET', '/cart', [], $cartToken);
        $keyByProductId = collect($current['items'] ?? [])->pluck('key', 'id');

        $cart = $current;
        foreach ($items as $item) {
            $productId = (int) $item['external_product_id'];
            $key = $keyByProductId->get($productId);

            [$cart, $cartToken] = $key
                ? $this->storeApi('POST', '/cart/update-item', [
                    'key' => $key,
                    'quantity' => $item['quantity'],
                ], $cartToken)
                : $this->storeApi('POST', '/cart/add-item', [
                    'id' => $productId,
                    'quantity' => $item['quantity'],
                ], $cartToken);
        }

        return $this->normalizeCart($cart, $cartToken);
    }

    public function checkout(string $externalCartId, array $paymentToken, array $shippingAddress = []): array
    {
        // Unlike Shopify's cartSubmitForCompletion (one consistent shape
        // regardless of which PSP is behind it), WooCommerce checkout needs
        // a payment_method id matching whichever gateway *plugin* is active
        // on this specific store (Stripe, PayPal, WooCommerce Payments,
        // ...), and payment_data shaped for that gateway. That mapping is
        // genuinely per-merchant, so it's read from this merchant's own
        // payment_token_exchange capability config rather than guessed at.
        $gateway = $this->gatewayFor($paymentToken['handler_id']);

        if (! $gateway) {
            throw new RuntimeException(
                "No WooCommerce gateway mapped for handler [{$paymentToken['handler_id']}] ".
                "on merchant [{$this->connection->merchant_id}]."
            );
        }

        [$order] = $this->storeApi('POST', '/checkout', array_filter([
            'payment_method' => $gateway,
            'payment_data' => [
                ['key' => 'token', 'value' => $paymentToken['token']],
            ],
            'billing_address' => $shippingAddress ?: null,
        ]), $externalCartId);

        return [
            'external_order_id' => isset($order['order_id']) ? (string) $order['order_id'] : null,
            'status' => isset($order['status']) ? $this->mapOrderStatus($order['status']) : 'pending',
        ];
    }

    public function getOrderStatus(string $externalOrderId): array
    {
        $order = $this->restApi('GET', "/orders/{$externalOrderId}");

        return [
            'external_order_id' => (string) $order['id'],
            'status' => $this->mapOrderStatus($order['status']),
            'total_cents' => $this->toCents($order['total']),
            'currency' => $order['currency'],
        ];
    }

    public function handleWebhook(array $payload, array $headers): void
    {
        // Verify X-WC-Webhook-Signature (HMAC-SHA256, base64) against your
        // webhook secret before trusting $payload — omitted for brevity.
        $topic = $headers['X-WC-Webhook-Topic'] ?? '';

        match (true) {
            str_starts_with($topic, 'order.') => \App\Jobs\RecordOrderEvent::dispatch(
                $this->connection->merchant_id,
                $this->normalizeWebhookOrder($payload),
                $this->mapOrderStatus($payload['status'] ?? ''),
            ),
            str_starts_with($topic, 'product.') => \App\Jobs\UpsertSyncedProduct::dispatch(
                $this->connection->merchant_id,
                $this->normalizeProduct($payload),
            ),
            default => null,
        };
    }

    private function gatewayFor(string $handlerId): ?string
    {
        // Lives in capability_configs.config for the payment_token_exchange
        // capability — that column exists precisely for per-merchant
        // settings like this one.
        $config = $this->connection->merchant
            ->capabilityConfigs()
            ->where('capability', 'payment_token_exchange')
            ->value('config') ?? [];

        return $config['gateway_mapping'][$handlerId] ?? null;
    }

    private function normalizeProduct(array $product): array
    {
        return [
            'external_id' => (string) $product['id'],
            'title' => $product['name'],
            'description' => $product['description'] ?: null,
            'price_cents' => $this->toCents($product['price'] ?: '0'),
            // Null when the merchant hasn't enabled per-product stock
            // tracking for this item — treat "not tracked" as in stock.
            'inventory_quantity' => $product['stock_quantity'] ?? 1,
            'raw_data' => $product,
        ];
    }

    private function normalizeWebhookOrder(array $payload): array
    {
        return [
            'external_order_id' => (string) $payload['id'],
            'status' => $this->mapOrderStatus($payload['status'] ?? ''),
            'total_cents' => $this->toCents($payload['total'] ?? '0'),
            'currency' => $payload['currency'] ?? 'USD',
        ];
    }

    private function normalizeCart(array $cart, ?string $cartToken): array
    {
        return [
            'external_cart_id' => $cartToken,
            'currency' => $cart['totals']['currency_code'] ?? 'USD',
            // Store API totals are already integers in the currency's minor
            // unit (e.g. "1999" for $19.99) — do NOT run these through
            // toCents(), unlike the wc/v3 fields below, which are decimal
            // major-unit strings ("19.99"). Same platform, two different
            // money representations depending which API answered.
            'total_cents' => (int) ($cart['totals']['total_price'] ?? 0),
        ];
    }

    private function mapOrderStatus(string $wooStatus): string
    {
        // Core WooCommerce doesn't distinguish "shipped" from "delivered"
        // the way Shopify's fulfillment status does — both typically
        // collapse into "completed" unless the store runs a shipment-
        // tracking plugin with its own status meta to hook into instead.
        return match ($wooStatus) {
            'processing' => 'confirmed',
            'completed' => 'delivered',
            'cancelled', 'failed', 'trash' => 'cancelled',
            'refunded' => 'returned',
            default => 'pending', // pending, on-hold
        };
    }

    private function toCents(string $decimalAmount): int
    {
        return (int) round(((float) $decimalAmount) * 100);
    }

    private function baseUrl(): string
    {
        return rtrim($this->connection->credentials['site_url'], '/');
    }

    private function restApi(string $method, string $path, array $params = []): array
    {
        $client = Http::withBasicAuth(
            $this->connection->credentials['consumer_key'],
            $this->connection->credentials['consumer_secret'],
        );

        $response = match (strtoupper($method)) {
            'GET' => $client->get("{$this->baseUrl()}/wp-json/wc/v3{$path}", $params),
            default => $client->post("{$this->baseUrl()}/wp-json/wc/v3{$path}", $params),
        };

        return $response->throw()->json();
    }

    /**
     * @return array{0: array, 1: ?string} decoded body, and the Cart-Token
     *         to carry into the next call on this same cart
     */
    private function storeApi(string $method, string $path, array $body, ?string $cartToken): array
    {
        $client = Http::withHeaders(array_filter(['Cart-Token' => $cartToken]));

        $response = match (strtoupper($method)) {
            'GET' => $client->get("{$this->baseUrl()}/wp-json/wc/store/v1{$path}"),
            default => $client->post("{$this->baseUrl()}/wp-json/wc/store/v1{$path}", $body),
        };

        $response->throw();

        return [$response->json(), $response->header('Cart-Token') ?? $cartToken];
    }
}
