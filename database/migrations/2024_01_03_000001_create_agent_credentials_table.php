<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();

            // 'gemini', 'chatgpt', 'claude', ... — informational, not itself
            // part of the auth check.
            $table->string('agent_platform');

            // Public half, sent in the Authorization header — safe to log,
            // safe to show in the dashboard list view.
            $table->string('key_id')->unique();

            // SHA-256 of the secret half. Not bcrypt/Hash::make() — that's
            // slow hashing for low-entropy human passwords defending
            // against brute force. A 40-char random secret doesn't need
            // that; a fast hash is the correct tool here, same as Sanctum
            // uses for its own tokens.
            $table->string('secret_hash');

            // Subset of capability names this credential may use — lets a
            // merchant hand one agent read-only catalog access and another
            // full checkout, without touching capability_configs itself.
            $table->json('scopes');

            $table->enum('status', ['active', 'revoked'])->default('active');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_credentials');
    }
};
