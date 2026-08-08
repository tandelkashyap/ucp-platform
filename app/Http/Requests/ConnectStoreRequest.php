<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ConnectStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasRoleOn($this->route('merchant'), 'owner', 'admin');
    }

    public function rules(): array
    {
        return [
            'platform' => ['required', Rule::in(['shopify', 'woocommerce', 'bigcommerce', 'magento'])],
            ...$this->credentialRules(),
        ];
    }

    /**
     * Mirrors exactly what each connector's own doc-block says it reads out
     * of `credentials` at call time — see the top of ShopifyConnector,
     * WooCommerceConnector, BigCommerceConnector, and MagentoConnector. If
     * a connector's credential needs ever change, this is the other file
     * to update.
     */
    private function credentialRules(): array
    {
        return match ($this->input('platform')) {
            'shopify' => [
                'credentials.shop_domain' => ['required', 'string'],
                'credentials.access_token' => ['required', 'string'],
            ],
            'woocommerce' => [
                'credentials.site_url' => ['required', 'url'],
                'credentials.consumer_key' => ['required', 'string'],
                'credentials.consumer_secret' => ['required', 'string'],
            ],
            'bigcommerce' => [
                'credentials.store_hash' => ['required', 'string'],
                'credentials.client_id' => ['required', 'string'],
                'credentials.access_token' => ['required', 'string'],
            ],
            'magento' => [
                'credentials.base_url' => ['required', 'url'],
                'credentials.access_token' => ['required', 'string'],
                // Not exposed in the dashboard form — set directly via the
                // API for local installs on a self-signed cert. Defaults
                // to true (verify) when absent.
                'credentials.verify_ssl' => ['sometimes', 'boolean'],
            ],
            default => [], // invalid platform value — caught by the platform rule itself
        };
    }
}
