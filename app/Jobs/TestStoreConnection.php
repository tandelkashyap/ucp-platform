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
 *
 * The constructor property is named $storeConnection, not $connection —
 * Queueable (below) already declares its own $connection property, which
 * holds the *queue* connection name (redis, database, sync, ...) a job
 * should run on. Same name, unrelated meaning; PHP refuses to compose the
 * trait into the class when they collide. This is exactly the kind of bug
 * `php -l` can't catch, since trait composition is resolved at class-load
 * time, not parse time.
 */
class TestStoreConnection implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private readonly StoreConnection $storeConnection)
    {
    }

    public function handle(ConnectorManager $connectors): void
    {
        try {
            $connectors->for($this->storeConnection)->getCatalog(['limit' => 1]);
        } catch (Throwable $e) {
            $this->storeConnection->update([
                'status' => 'error',
                'last_error' => $e->getMessage(),
            ]);

            return;
        }

        $this->storeConnection->update(['status' => 'connected', 'last_error' => null]);

        // First working connection is what "pending" was actually waiting
        // for — a merchant with a connected store and a synced catalog
        // isn't pending anything anymore.
        if ($this->storeConnection->merchant->status === 'pending') {
            $this->storeConnection->merchant->update(['status' => 'active']);
        }

        SyncMerchantCatalog::dispatch($this->storeConnection->merchant);
    }
}
