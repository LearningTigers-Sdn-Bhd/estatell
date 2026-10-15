<?php

namespace App\Actions\Onboarding;

use App\Actions\Property\UpdatePropertySettings;
use App\Enums\RegistrationCardScope;
use App\Models\Property;
use Illuminate\Support\Facades\DB;

class SaveOnboardingCheckInEssentials
{
    public function __construct(private UpdatePropertySettings $updatePropertySettings) {}

    /**
     * Save the Check-in essentials form through UpdatePropertySettings. A deposit of null (not collected) is saved as 0,
     * so no deposit is asked at check-in.
     *
     * @param  array{check_in_time: string, check_out_time: string, registration_card_scope: RegistrationCardScope, card_required_for_check_in: bool, id_document_retention_days: int|null, security_deposit_amount: string|null}  $settings
     */
    public function __invoke(Property $property, array $settings): void
    {
        DB::transaction(function () use ($property, $settings): void {
            Property::query()->lockForUpdate()->findOrFail($property->id);

            ($this->updatePropertySettings)($property, [
                ...$settings,
                'security_deposit_amount' => $settings['security_deposit_amount'] ?? '0',
            ]);
        });
    }
}
