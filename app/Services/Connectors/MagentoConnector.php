<?php

namespace App\Services\Connectors;

use App\Contracts\CommerceConnector;
use App\Models\StoreConnection;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * NOTE ON API SURFACE: Magento's REST API is the most different of the
 * four connectors here in one specific way — products are keyed by SKU
 * (a string) in most endpoints, not a numeric/GID id the way the other
 * three do it. Everything else rhymes with patterns already established:
 * guest-cart items get their own id once added (same fetch-then-map shape
 * as WooCommerce/BigCommerce), and address is a mandatory pre-step before
 * checkout will accept the cart (same as BigCommerce).
 *
 * The one real limitation, not a design choice: core Magento 2
 * (Open Source/Community Edition — what a local install almost always is)
 * has no built-in webhook system. handleWebhook() below is effectively
 * inert on a default install; lean on SyncMerchantCatalog running on a
 * tighter schedule for Magento specifically, since there's no push
 * mechanism to fall back from. Adobe Commerce (the paid edition) added
 * native webhook subscriptions separately — irrelevant if this is
 * pointed at Open Source.
 *
 * `credentials` needs: base_url (no trailing slash), access_token (an
 * Integration token from Admin > System > Integrations — not an admin
 * username/password). Optional: verify_ssl (bool, defaults true) — local
 * installs commonly run on a self-signed cert; see restApi() below rather
 * than disabling verification globally.
 */
class MagentoConnector implements CommerceConnector
{
    public function __construct(private readonly StoreConnection $connection)
    {
    }

    public function getCatalog(array $filters = []): Collection
    {
        $response = $this->restApi('GET', '/products', [
            'searchCriteria[pageSize]' => $filters['limit'] ?? 50,
        ]);

        return collect($response['items'] ?? [])->map(fn (array $product) => $this->normalizeProduct($product));
    }

    public function getProduct(string $externalId): ?array
    {
        // $externalId is the SKU here, not a numeric id — see class doc block.
        try {
            return $this->normalizeProduct($this->restApi('GET', '/products/'.rawurlencode($externalId)));
        } catch (RequestException $e) {
            if ($e->response->status() === 404) {
                return null;
            }

            throw $e;
        }
    }

    public function createCart(array $items): array
    {
        $cartId = (string) $this->restApi('POST', '/guest-carts', []);

        foreach ($items as $item) {
            $this->addItem($cartId, $item['external_product_id'], $item['quantity']);
        }

        return $this->normalizeCart($cartId);
    }

    public function updateCart(string $externalCartId, array $items): array
    {
        $current = $this->restApi('GET', "/guest-carts/{$externalCartId}/items");
        $itemIdBySku = collect($current)->pluck('item_id', 'sku');

        foreach ($items as $item) {
            $sku = $item['external_product_id'];
            $itemId = $itemIdBySku->get($sku);

            if ($itemId) {
                $this->restApi('PUT', "/guest-carts/{$externalCartId}/items/{$itemId}", [
                    'cartItem' => ['sku' => $sku, 'qty' => $item['quantity'], 'quote_id' => $externalCartId],
                ]);
            } else {
                $this->addItem($externalCartId, $sku, $item['quantity']);
            }
        }

        return $this->normalizeCart($externalCartId);
    }

    public function checkout(string $externalCartId, array $paymentToken, array $shippingAddress = []): array
    {
        if (! ($shippingAddress['email'] ?? null)) {
            throw new RuntimeException(
                'Magento checkout requires an email address for a guest cart — '.
                'include "email" in shipping_address.'
            );
        }

        if ($shippingAddress) {
            $this->restApi('POST', "/guest-carts/{$externalCartId}/shipping-information", [
                'addressInformation' => [
                    'shipping_address' => $shippingAddress,
                    'billing_address' => $shippingAddress,
                    'shipping_carrier_code' => 'flatrate',
                    'shipping_method_code' => 'flatrate',
                ],
            ]);
        }

        // 'checkmo' (Check/Money Order) is Magento's always-available
        // offline payment method on a bare install with no gateway
        // configured — the right default for local testing specifically,
        // not something to carry into a real deployment unexamined.
        //
        // email is required here for a *guest* cart specifically — which
        // is the only kind createCart() ever creates — since there's no
        // logged-in customer for Magento to pull one from otherwise. Pulled
        // from the shipping address since that's the only place UCP's
        // checkout call gives this connector an email at all; a genuine
        // design seam (address and buyer-email are different concerns
        // being read from the same object), not a clean design choice.
        $orderId = $this->restApi('POST', "/guest-carts/{$externalCartId}/payment-information", [
            'paymentMethod' => ['method' => $paymentToken['handler_id'] ?? 'checkmo'],
            'billingAddress' => $shippingAddress ?: null,
            'email' => $shippingAddress['email'] ?? null,
        ]);

        if (! $orderId) {
            return ['external_order_id' => null, 'status' => 'pending'];
        }

        // Query Magento for the order's actual status rather than assume
        // "an order ID came back" means "confirmed" — checkmo (and other
        // offline payment methods) leave the order genuinely Pending until
        // a human processes it in the admin, and that's correct on
        // Magento's side, not a delay to paper over. Reuses the same
        // mapOrderStatus() getOrderStatus() already applies, so the two
        // paths can't silently disagree with each other later.
        return $this->getOrderStatus((string) $orderId);
    }

    public function getOrderStatus(string $externalOrderId): array
    {
        $order = $this->restApi('GET', "/orders/{$externalOrderId}");

        return [
            'external_order_id' => (string) $order['entity_id'],
            'status' => $this->mapOrderStatus($order['status']),
            'total_cents' => $this->toCents($order['grand_total'] ?? 0),
            'currency' => $order['order_currency_code'] ?? 'USD',
        ];
    }

    public function handleWebhook(array $payload, array $headers): void
    {
        // Intentionally empty — see the class doc block. Nothing calls
        // this on a default Magento Open Source install; it exists so a
        // real webhook extension has somewhere to plug into later.
    }

    private function addItem(string $cartId, string $sku, int $quantity): void
    {
        $this->restApi('POST', "/guest-carts/{$cartId}/items", [
            'cartItem' => ['sku' => $sku, 'qty' => $quantity, 'quote_id' => $cartId],
        ]);
    }

    private function normalizeProduct(array $product): array
    {
        $stockItem = $product['extension_attributes']['stock_item'] ?? [];

        return [
            'external_id' => $product['sku'],
            'title' => $product['name'],
            // custom_attributes is a list of {attribute_code, value}
            // pairs, not a flat object — description usually lives here.
            'description' => collect($product['custom_attributes'] ?? [])
                ->firstWhere('attribute_code', 'description')['value'] ?? null,
            'price_cents' => $this->toCents($product['price'] ?? 0),
            'inventory_quantity' => (int) ($stockItem['qty'] ?? 0),
            'raw_data' => $product,
        ];
    }

    private function normalizeCart(string $cartId): array
    {
        $totals = $this->restApi('GET', "/guest-carts/{$cartId}/totals");

        return [
            'external_cart_id' => $cartId,
            'currency' => $totals['quote_currency_code'] ?? 'USD',
            'total_cents' => $this->toCents($totals['grand_total'] ?? 0),
        ];
    }

    private function mapOrderStatus(string $magentoStatus): string
    {
        return match ($magentoStatus) {
            'pending', 'pending_payment' => 'pending',
            'processing' => 'confirmed',
            'complete' => 'delivered',
            'canceled', 'closed' => 'cancelled',
            default => 'pending', // holded, fraud, payment_review, ...
        };
    }

    private function toCents(int|float $amount): int
    {
        return (int) round($amount * 100);
    }

    private function restApi(string $method, string $path, array $params = []): mixed
    {
        $credentials = $this->connection->credentials;
        $baseUrl = rtrim($credentials['base_url'], '/');

        // Magento is genuinely known for slower response times than the
        // other three platforms here, especially on a local install without
        // production-tier caching/opcache tuning — 30s (Laravel's default)
        // isn't always enough, particularly on a cold first request.
        $client = Http::withToken($credentials['access_token'])->timeout(60);

        // Never disable verification by default — only if the connection's
        // own credentials explicitly opt in, which a real deployment never
        // would. A local Magento install on a self-signed cert is the
        // intended use for this.
        if (($credentials['verify_ssl'] ?? true) === false) {
            $client = $client->withOptions(['verify' => false]);
        }

        $response = match (strtoupper($method)) {
            'GET' => $client->get("{$baseUrl}/rest/V1{$path}", $params),
            'PUT' => $client->put("{$baseUrl}/rest/V1{$path}", $params),
            default => $client->post("{$baseUrl}/rest/V1{$path}", $params),
        };

        return $response->throw()->json();
    }
}
