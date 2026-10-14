<?php

namespace App\Http\Requests\Partner;

use App\Http\Requests\Admin\CreatePropertyForOwnerRequest;

/**
 * The same form as the admin Create property Sheet. It is refused with a 403 while the admin has not switched on
 * "Can create properties" for the partner.
 */
class StorePartnerPropertyRequest extends CreatePropertyForOwnerRequest
{
    public function authorize(): bool
    {
        return $this->user()?->partner?->can_create_properties === true;
    }
}
