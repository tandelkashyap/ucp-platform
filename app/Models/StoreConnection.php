<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreConnection extends Model
{
    protected $fillable = [
        'merchant_id',
        'platform',
        'external_store_identifier',
        'credentials',
        'status',
        'last_error',
        'last_synced_at',
    ];

    protected $casts = [
        // Laravel encrypts on write and decrypts on read using APP_KEY —
        // access tokens/API keys never sit in the database in plain text.
        // Layer in KMS/Vault-backed key rotation later; this is the right
        // default to start with.
        'credentials' => 'encrypted:array',
        'last_synced_at' => 'datetime',
    ];

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }
}
