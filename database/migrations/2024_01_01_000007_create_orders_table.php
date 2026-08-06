<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cart_id')->nullable()->constrained()->nullOnDelete();

            $table->string('external_order_id')->nullable();
            $table->enum('status', ['pending', 'confirmed', 'shipped', 'delivered', 'returned', 'cancelled'])
                ->default('pending');
            $table->unsignedBigInteger('total_cents');
            $table->char('currency', 3)->default('USD');

            // Which AI platform placed this order — 'gemini', 'chatgpt', etc.
            // This is the column your agent-vs-human analytics is built on.
            $table->string('agent_platform')->nullable();

            $table->timestamps();

            $table->index(['merchant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
