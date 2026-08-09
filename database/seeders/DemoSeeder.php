<?php

namespace Database\Seeders;

use App\Models\AgentCredential;
use App\Models\Merchant;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Gets a fresh install back to a testable state without any real store —
 * inserts a merchant, a 'connected' store_connection, and two products
 * directly for each platform below, bypassing the connector entirely.
 * Good enough for exercising catalog/cart/checkout and the dashboard UI
 * locally. NOT a substitute for testing an actual connector against a
 * real platform — nothing here ever calls Shopify/WooCommerce/BigCommerce/
 * Magento, so it proves nothing about any *Connector class actually
 * working. Magento specifically now has a real, connector-verified
 * alternative if one's available locally — see the project README.
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

        $this->seedMerchant(
            $user,
            slug: 'demo-store',
            name: 'Demo Store',
            platform: 'shopify',
            identifier: 'demo-store.myshopify.com',
            credentials: ['shop_domain' => 'demo-store.myshopify.com', 'access_token' => 'demo-token'],
        );

        $this->seedMerchant(
            $user,
            slug: 'demo-store-magento',
            name: 'Demo Store (Magento)',
            platform: 'magento',
            identifier: 'https://demo-magento.test',
            credentials: ['base_url' => 'https://demo-magento.test', 'access_token' => 'demo-token'],
        );

        $this->command->info('Demo user: demo@example.com / password');
        $this->command->warn('Agent tokens above are one-time — save them now, they are not stored anywhere and will not be shown again.');
    }

    /**
     * @param array<string, string> $credentials
     */
    private function seedMerchant(
        User $user,
        string $slug,
        string $name,
        string $platform,
        string $identifier,
        array $credentials,
    ): Merchant {
        $merchant = Merchant::firstOrCreate(['slug' => $slug], ['name' => $name, 'status' => 'active']);

        $merchant->users()->syncWithoutDetaching([$user->id => ['role' => 'owner']]);

        // MerchantObserver seeds these disabled by default — turn on what's
        // needed to actually exercise the UCP-facing endpoints.
        $merchant->capabilityConfigs()
            ->whereIn('capability', ['catalog', 'cart', 'checkout'])
            ->update(['enabled' => true]);

        $merchant->storeConnections()->firstOrCreate(
            ['platform' => $platform],
            ['external_store_identifier' => $identifier, 'credentials' => $credentials, 'status' => 'connected'],
        );

        foreach ($this->demoProducts() as $product) {
            $merchant->products()->updateOrCreate(
                ['external_id' => $product['external_id']],
                [...$product, 'currency' => 'USD', 'synced_at' => now()],
            );
        }

        $result = AgentCredential::generate($merchant, 'demo', ['catalog', 'cart', 'checkout']);

        $this->command->info("--- {$name} ---");
        $this->command->info("Merchant ID: {$merchant->id} ({$slug})");
        $this->command->info("Agent token: {$result['plaintext']}");

        return $merchant;
    }

    private function demoProducts(): array
    {
        return [
            ['external_id' => 'demo-1', 'title' => 'Demo Widget', 'price_cents' => 1999, 'inventory_quantity' => 25],
            ['external_id' => 'demo-2', 'title' => 'Demo Gadget', 'price_cents' => 4500, 'inventory_quantity' => 10],
        ];
    }
}
