<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('capability_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();

            // catalog, cart, checkout, identity_linking, payment_token_exchange, ...
            $table->string('capability');

            $table->boolean('enabled')->default(false);
            $table->json('config')->nullable();
            $table->timestamps();

            $table->unique(['merchant_id', 'capability']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('capability_configs');
    }
};
