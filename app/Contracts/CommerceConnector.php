<?php

namespace App\Contracts;

use Illuminate\Support\Collection;

/**
 * One implementation per e-commerce platform (Shopify, WooCommerce, BigCommerce, ...).
 * Nothing in the UCP protocol layer or control plane should ever import a
 * connector class directly — everything goes through this interface, resolved
 * per-merchant by App\Services\ConnectorManager.
 */
interface CommerceConnector
{
    /**
     * Pull the catalog from the underlying platform, normalized into arrays
     * shaped like the `products` table. Called from a queued sync job —
     * never called synchronously from an agent-facing request.
     *
     * @return Collection<int, array{
     *     external_id: string, title: string, description: ?string,
     *     price_cents: int, inventory_quantity: int, raw_data: array
     * }>
     */
    public function getCatalog(array $filters = []): Collection;

    /**
     * Fetch a single product by the platform's own identifier.
     */
    public function getProduct(string $externalId): ?array;

    /**
     * Create a cart on the underlying platform.
     *
     * @param array<int, array{external_product_id: string, quantity: int}> $items
     * @return array{external_cart_id: string, currency: string, total_cents: int}
     */
    public function createCart(array $items): array;

    /**
     * Update line items on an existing external cart.
     *
     * @param array<int, array{external_product_id: string, quantity: int}> $items
     * @return array{external_cart_id: string, currency: string, total_cents: int}
     */
    public function updateCart(string $externalCartId, array $items): array;

    /**
     * Complete checkout using an already-acquired payment token (the result of
     * the UCP payment token exchange, not a raw card number).
     *
     * $shippingAddress is optional because Shopify/WooCommerce's simplified
     * flows here don't require it — but treat that as a gap in this slice,
     * not a real-world one. Every platform needs an address to actually
     * calculate shipping and tax; BigCommerce is just the one where the API
     * makes that impossible to skip, since billing address and shipping
     * consignments are mandatory sub-resources of its Checkout.
     *
     * @return array{external_order_id: ?string, status: string}
     */
    public function checkout(string $externalCartId, array $paymentToken, array $shippingAddress = []): array;

    /**
     * Poll the underlying platform for current order status — a fallback for
     * when webhooks are delayed or dropped.
     *
     * @return array{external_order_id: string, status: string, total_cents: int, currency: string}
     */
    public function getOrderStatus(string $externalOrderId): array;

    /**
     * Handle an inbound webhook from the underlying platform (inventory
     * changed, order updated, ...). Implementations should verify the
     * platform's signature before trusting $payload, then dispatch a queued
     * job rather than processing inline.
     */
    public function handleWebhook(array $payload, array $headers): void;
}
