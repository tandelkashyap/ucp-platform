<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();

            // The underlying platform's own product id (Shopify GID, WooCommerce id, etc).
            $table->string('external_id');

            $table->string('title');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('price_cents');
            $table->char('currency', 3)->default('USD');
            $table->unsignedInteger('inventory_quantity')->default(0);

            // Full normalized payload from the connector, for anything the
            // typed columns above don't capture yet.
            $table->json('raw_data')->nullable();

            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['merchant_id', 'external_id']);
            $table->index(['merchant_id', 'title']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
