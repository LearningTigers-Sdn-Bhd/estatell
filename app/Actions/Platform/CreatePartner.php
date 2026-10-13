<?php

namespace App\Actions\Platform;

use App\Enums\PartnerStatus;
use App\Models\Partner;
use App\Services\Platform\PartnerSignupCode;
use Illuminate\Support\Facades\DB;

class CreatePartner
{
    public function __construct(private PartnerSignupCode $signupCode) {}

    /**
     * The step every way of adding a partner shares: the partner row, its signup code and its property code
     * counter, which starts at 10100 so the first property is XX10101.
     *
     * Call this inside the caller's transaction, so a failed login or invitation leaves no partner and no
     * reserved prefix. The request has already checked that the prefix is free.
     */
    public function __invoke(string $name, string $prefix, PartnerStatus $status): Partner
    {
        $partner = (new Partner)->forceFill([
            'name' => $name,
            'code_prefix' => $prefix,
            'signup_code' => $this->signupCode->generate(),
            'status' => $status,
        ]);
        $partner->save();

        DB::table('property_code_counters')->insert(['prefix' => $prefix, 'last_number' => 10100]);

        return $partner;
    }
}
