<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class AgentCredential extends Model
{
    protected $fillable = [
        'merchant_id', 'agent_platform', 'key_id', 'secret_hash', 'scopes', 'status', 'expires_at',
    ];

    protected $casts = [
        'scopes' => 'array',
        'expires_at' => 'datetime',
        'last_used_at' => 'datetime',
    ];

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    /**
     * Creates a credential and returns the one-time plaintext token
     * alongside it. This is the only moment the secret half exists outside
     * whatever the caller does with it — only secret_hash is ever
     * persisted, so there's no "view secret again" feature to build later.
     *
     * @return array{credential: self, plaintext: string}
     */
    public static function generate(Merchant $merchant, string $agentPlatform, array $scopes): array
    {
        $keyId = 'agt_'.Str::random(16);
        $secret = Str::random(40);

        $credential = static::create([
            'merchant_id' => $merchant->id,
            'agent_platform' => $agentPlatform,
            'key_id' => $keyId,
            'secret_hash' => hash('sha256', $secret),
            'scopes' => $scopes,
            'status' => 'active',
        ]);

        return ['credential' => $credential, 'plaintext' => "{$keyId}.{$secret}"];
    }

    public function verify(string $secret): bool
    {
        // Timing-safe comparison — a plain === here would leak how many
        // leading bytes matched via response-time differences.
        return hash_equals($this->secret_hash, hash('sha256', $secret));
    }

    public function hasScope(string $capability): bool
    {
        return in_array($capability, $this->scopes, true);
    }
}
