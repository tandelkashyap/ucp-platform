<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CapabilityConfig extends Model
{
    protected $fillable = ['merchant_id', 'capability', 'enabled', 'config'];

    protected $casts = [
        'enabled' => 'boolean',
        'config' => 'array',
    ];

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }
}
