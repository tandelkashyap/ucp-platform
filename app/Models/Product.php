<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    protected $fillable = [
        'merchant_id',
        'external_id',
        'title',
        'description',
        'price_cents',
        'currency',
        'inventory_quantity',
        'raw_data',
        'synced_at',
    ];

    protected $casts = [
        'raw_data' => 'array',
        'synced_at' => 'datetime',
    ];

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function cartItems(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }
}
