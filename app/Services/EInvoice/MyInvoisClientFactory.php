<?php

namespace App\Services\EInvoice;

use App\Enums\EInvoiceEnvironment;
use App\Models\PropertyEInvoiceSetting;

class MyInvoisClientFactory
{
    /**
     * Whether the hotel can log in to MyInvois: e-invoicing is on, and the connection is the offline test or has a
     * Client ID and secret.
     */
    public static function isReady(PropertyEInvoiceSetting $setting): bool
    {
        if (! $setting->enabled) {
            return false;
        }

        return $setting->api_environment === EInvoiceEnvironment::Mock || (! empty($setting->client_id) && ! empty($setting->client_secret));
    }

    public static function forSetting(PropertyEInvoiceSetting $setting): MyInvoisClient|MockMyInvoisClient
    {
        if ($setting->api_environment === EInvoiceEnvironment::Mock) {
            return new MockMyInvoisClient;
        }

        return new MyInvoisClient($setting);
    }
}
