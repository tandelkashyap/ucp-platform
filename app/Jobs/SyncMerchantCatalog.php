<?php

namespace App\Jobs;

use App\Models\Merchant;
use App\Services\ConnectorManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Runs on a schedule (and can be triggered from a webhook handler for a
 * faster path) to pull the catalog from the merchant's connected platform
 * and upsert it into `products`. Agent-facing reads never touch the
 * connector directly — see CatalogController.
 */
class SyncMerchantCatalog implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private readonly Merchant $merchant)
    {
    }

    public function handle(ConnectorManager $connectors): void
    {
        $connection = $this->merchant->activeStoreConnection();

        $connector = $connectors->for($connection);

        $connector->getCatalog()->each(
            fn (array $data) => UpsertSyncedProduct::dispatchSync($this->merchant->id, $data)
        );

        $connection->update(['last_synced_at' => now()]);
    }
}
