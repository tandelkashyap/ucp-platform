<?php

namespace App\Observers;

use App\Models\Merchant;

class MerchantObserver
{
    public function created(Merchant $merchant): void
    {
        foreach (config('ucp.default_capabilities', []) as $capability) {
            $merchant->capabilityConfigs()->create([
                'capability' => $capability,
                'enabled' => false,
            ]);
        }
    }
}
