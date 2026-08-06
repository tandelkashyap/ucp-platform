<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();

            // Set once the connector actually creates the cart on the underlying platform.
            $table->string('external_cart_id')->nullable();

            // Ties this cart back to whichever agent session started it.
            $table->string('agent_session_id')->nullable();

            $table->enum('status', ['open', 'checked_out', 'abandoned'])->default('open');
            $table->char('currency', 3)->default('USD');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('carts');
    }
};
