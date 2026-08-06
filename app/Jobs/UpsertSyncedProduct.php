<?php

namespace App\Jobs;

use App\Models\Merchant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Platform-agnostic on purpose. Each connector normalizes its own payload
 * (Shopify's GraphQL shape, Shopify's REST webhook shape, WooCommerce's
 * REST shape — all different) and dispatches this with the one common
 * array shape. Nothing here knows or cares which platform the data came
 * from, which is the whole point of the CommerceConnector interface.
 */
class UpsertSyncedProduct implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param array{
     *     external_id: string, title: string, description: ?string,
     *     price_cents: int, inventory_quantity: int, raw_data: array
     * } $product
     */
    public function __construct(
        private readonly int $merchantId,
        private readonly array $product,
    ) {
    }

    public function handle(): void
    {
        Merchant::findOrFail($this->merchantId)
            ->products()
            ->updateOrCreate(
                ['external_id' => $this->product['external_id']],
                [...$this->product, 'synced_at' => now()],
            );
    }
}
