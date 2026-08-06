<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    protected $fillable = [
        'merchant_id',
        'cart_id',
        'external_order_id',
        'status',
        'total_cents',
        'currency',
        'agent_platform',
    ];

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(OrderEvent::class);
    }
}
