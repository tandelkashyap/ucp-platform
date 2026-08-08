<?php

use App\Services\Connectors\BigCommerceConnector;
use App\Services\Connectors\MagentoConnector;
use App\Services\Connectors\ShopifyConnector;
use App\Services\Connectors\WooCommerceConnector;

return [

    /*
    |--------------------------------------------------------------------
    | Connector registry
    |--------------------------------------------------------------------
    | Maps each supported platform to the class implementing
    | App\Contracts\CommerceConnector for it. Four platforms in now —
    | the next one added is the real test of whether this still
    | generalizes past "the first four happened to fit."
    */
    'connectors' => [
        'shopify' => ShopifyConnector::class,
        'woocommerce' => WooCommerceConnector::class,
        'bigcommerce' => BigCommerceConnector::class,
        'magento' => MagentoConnector::class,
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
