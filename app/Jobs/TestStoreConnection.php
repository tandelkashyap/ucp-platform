<?php

namespace App\Jobs;

use App\Models\StoreConnection;
use App\Services\ConnectorManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Runs once, right after a merchant submits credentials, so a typo'd token
 * shows up as an error on the connection immediately rather than silently
 * failing on the first real sync. Deliberately catches Throwable broadly —
 * any failure here (bad credentials, wrong store hash, network error, a
 * missing field this connector expected) should land as a visible
 * connection status, not crash the queue worker.
 */
class TestStoreConnection implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private readonly StoreConnection $connection)
    {
    }

    public function handle(ConnectorManager $connectors): void
    {
        try {
            $connectors->for($this->connection)->getCatalog(['limit' => 1]);
        } catch (Throwable $e) {
            $this->connection->update([
                'status' => 'error',
                'last_error' => $e->getMessage(),
            ]);

            return;
        }

        $this->connection->update(['status' => 'connected', 'last_error' => null]);

        SyncMerchantCatalog::dispatch($this->connection->merchant);
    }
}
