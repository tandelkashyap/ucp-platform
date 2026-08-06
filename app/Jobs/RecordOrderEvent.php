<?php

namespace App\Jobs;

use App\Models\Merchant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Also platform-agnostic — see UpsertSyncedProduct for why.
 */
class RecordOrderEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param array{external_order_id: string, status: string, total_cents: int, currency: string} $order
     * @param string $eventType one of orders' event_type enum values (placed, confirmed, shipped, delivered, returned, cancelled)
     */
    public function __construct(
        private readonly int $merchantId,
        private readonly array $order,
        private readonly string $eventType,
    ) {
    }

    public function handle(): void
    {
        $order = Merchant::findOrFail($this->merchantId)
            ->orders()
            ->updateOrCreate(
                ['external_order_id' => $this->order['external_order_id']],
                $this->order,
            );

        $order->events()->create([
            'event_type' => $this->eventType,
            'payload' => $this->order,
            'occurred_at' => now(),
        ]);
    }
}
