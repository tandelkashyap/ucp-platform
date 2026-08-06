<?php

namespace App\Providers;

use App\Services\ConnectorManager;
use Illuminate\Support\ServiceProvider;

class ConnectorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ConnectorManager::class);
    }
}
