<?php

namespace App\Providers;

use App\Models\Merchant;
use App\Observers\MerchantObserver;
use App\Policies\MerchantPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class MerchantServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Merchant::observe(MerchantObserver::class);

        Gate::policy(Merchant::class, MerchantPolicy::class);
    }
}
