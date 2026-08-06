<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Merchant extends Model
{
    protected $fillable = ['name', 'slug', 'status'];

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('role')->withTimestamps();
    }

    public function storeConnections(): HasMany
    {
        return $this->hasMany(StoreConnection::class);
    }

    /**
     * The one connection actually used for live agent traffic. A merchant
     * could in principle have more than one row in store_connections
     * (e.g. mid-migration between platforms), but only one should ever be
     * 'connected' at a time — this is the single place that assumption
     * lives, rather than every caller repeating the same where() clause.
     */
    public function activeStoreConnection(): StoreConnection
    {
        return $this->storeConnections()->where('status', 'connected')->firstOrFail();
    }

    public function capabilityConfigs(): HasMany
    {
        return $this->hasMany(CapabilityConfig::class);
    }

    public function agentCredentials(): HasMany
    {
        return $this->hasMany(AgentCredential::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function carts(): HasMany
    {
        return $this->hasMany(Cart::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Used at the top of every UCP-facing endpoint to gate access —
     * see CatalogController for the pattern.
     */
    public function hasCapability(string $capability): bool
    {
        return $this->capabilityConfigs()
            ->where('capability', $capability)
            ->where('enabled', true)
            ->exists();
    }
}
