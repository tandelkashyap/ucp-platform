<?php

use App\Services\Connectors\BigCommerceConnector;
use App\Services\Connectors\ShopifyConnector;
use App\Services\Connectors\WooCommerceConnector;

return [

    /*
    |--------------------------------------------------------------------
    | Connector registry
    |--------------------------------------------------------------------
    | Maps each supported platform to the class implementing
    | App\Contracts\CommerceConnector for it. All three of the platforms
    | this was originally scoped for are now here — the next platform
    | added is the fourth data point on whether the interface generalizes.
    */
    'connectors' => [
        'shopify' => ShopifyConnector::class,
        'woocommerce' => WooCommerceConnector::class,
        'bigcommerce' => BigCommerceConnector::class,
    ],

    /*
    |--------------------------------------------------------------------
    | Default capabilities
    |--------------------------------------------------------------------
    | Seeded into capability_configs (disabled) whenever a merchant is
    | created, so the dashboard has something to list and toggle on.
    */
    'default_capabilities' => [
        'catalog',
        'cart',
        'checkout',
        'identity_linking',
        'payment_token_exchange',
    ],

];
