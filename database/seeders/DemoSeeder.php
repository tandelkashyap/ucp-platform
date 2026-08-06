<?php

namespace Database\Seeders;

use App\Models\AgentCredential;
use App\Models\Merchant;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Gets a fresh install back to a testable state without a real Shopify/
 * WooCommerce/BigCommerce dev store — inserts a merchant, a 'connected'
 * store_connection, and two products directly, bypassing the connector
 * entirely. Good enough for exercising catalog/cart/checkout locally.
 * NOT a substitute for testing an actual connector against a real
 * platform — nothing here ever calls Shopify/WooCommerce/BigCommerce, so
 * it proves nothing about ShopifyConnector etc. actually working.
 *
 * Run with: php artisan db:seed --class=DemoSeeder
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::firstOrCreate(
            ['email' => 'demo@example.com'],
            ['name' => 'Demo User', 'password' => 'password'],
        );

        $merchant = Merchant::firstOrCreate(
            ['slug' => 'demo-store'],
            ['name' => 'Demo Store', 'status' => 'active'],
        );

        $merchant->users()->syncWithoutDetaching([$user->id => ['role' => 'owner']]);

        // MerchantObserver seeds these disabled by default — turn on what's
        // needed to actually exercise the UCP-facing endpoints.
        $merchant->capabilityConfigs()
            ->whereIn('capability', ['catalog', 'cart', 'checkout'])
            ->update(['enabled' => true]);

        $merchant->storeConnections()->firstOrCreate(
            ['platform' => 'shopify'],
            [
                'external_store_identifier' => 'demo-store.myshopify.com',
                'credentials' => ['shop_domain' => 'demo-store.myshopify.com', 'access_token' => 'demo-token'],
                'status' => 'connected',
            ],
        );

        foreach ($this->demoProducts() as $product) {
            $merchant->products()->updateOrCreate(
                ['external_id' => $product['external_id']],
                [...$product, 'currency' => 'USD', 'synced_at' => now()],
            );
        }

        $result = AgentCredential::generate($merchant, 'demo', ['catalog', 'cart', 'checkout']);

        $this->command->info('Demo user:   demo@example.com / password');
        $this->command->info("Merchant ID: {$merchant->id}");
        $this->command->info("Agent token: {$result['plaintext']}");
        $this->command->warn('Save that token now — it is not stored anywhere and will not be shown again.');
    }

    private function demoProducts(): array
    {
        return [
            ['external_id' => 'demo-1', 'title' => 'Demo Widget', 'price_cents' => 1999, 'inventory_quantity' => 25],
            ['external_id' => 'demo-2', 'title' => 'Demo Gadget', 'price_cents' => 4500, 'inventory_quantity' => 10],
        ];
    }
}
