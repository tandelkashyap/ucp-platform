<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->enum('platform', ['shopify', 'woocommerce', 'bigcommerce']);

            // e.g. Shopify shop domain, WooCommerce site URL — whatever the
            // connector needs to identify which storefront this is.
            $table->string('external_store_identifier');

            // Encrypted via the model cast below — access tokens, API keys, etc.
            // live here, never in plain columns.
            $table->text('credentials');

            $table->enum('status', ['connecting', 'connected', 'error', 'disconnected'])
                ->default('connecting');
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['merchant_id', 'platform']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_connections');
    }
};
