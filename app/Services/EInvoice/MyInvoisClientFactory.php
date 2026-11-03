<?php

namespace App\Services\EInvoice;

use App\Enums\EInvoiceEnvironment;
use App\Models\PropertyEInvoiceSetting;

class MyInvoisClientFactory
{
    public static function forSetting(PropertyEInvoiceSetting $setting): MyInvoisClient|MockMyInvoisClient
    {
        if ($setting->api_environment === EInvoiceEnvironment::Mock) {
            return new MockMyInvoisClient;
        }

        return new MyInvoisClient($setting);
    }
}
