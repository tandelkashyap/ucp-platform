<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * MySQL-specific syntax (ALTER ... MODIFY) — this project's assumed to be
 * running on MySQL per the Laragon setup used throughout. Adjust for
 * Postgres/SQLite if this ever needs to run on a different driver.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            "ALTER TABLE store_connections MODIFY platform ENUM('shopify', 'woocommerce', 'bigcommerce', 'magento') NOT NULL"
        );
    }

    public function down(): void
    {
        DB::statement(
            "ALTER TABLE store_connections MODIFY platform ENUM('shopify', 'woocommerce', 'bigcommerce') NOT NULL"
        );
    }
};
