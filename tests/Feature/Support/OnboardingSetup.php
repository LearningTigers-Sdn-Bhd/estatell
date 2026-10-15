<?php

use App\Enums\PaymentMethodType;
use App\Enums\PropertyType;
use App\Enums\SystemTransactionCode;
use App\Models\PaymentMethod;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\Rate;
use App\Models\RatePlan;
use App\Models\RegistrationCardTerms;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Tax;
use App\Models\TermsDocument;
use App\Models\TransactionCode;
use App\Services\Onboarding\BasicRatePlans;

/**
 * Finishes the Basics step of an onboarding property: an address and a settings row. The factory already sets the
 * type, name, city and country.
 */
function completeOnboardingBasics(Property $property): void
{
    $property->update(['address_line1' => '12 Jalan Pantai']);

    PropertySetting::factory()->for($property)->create();
}

/**
 * Makes every required onboarding step Done with the smallest real data: Basics, check-in times and a published
 * terms version, a room type with a room, an enabled tax on the room code, a payment method, and 30 days of basic rate.
 */
function completeRequiredOnboardingSteps(Property $property): void
{
    completeOnboardingBasics($property);

    PropertySetting::query()->where('property_id', $property->id)->update([
        'check_in_time' => '14:00:00',
        'check_out_time' => '12:00:00',
    ]);

    $terms = TermsDocument::factory()->for($property)->default()->create();
    RegistrationCardTerms::factory()->create(['terms_document_id' => $terms->id, 'body' => 'Guests follow the house rules.']);

    $roomType = RoomType::factory()->for($property)->create();
    Room::factory()->for($property)->create(['room_type_id' => $roomType->id]);

    $roomCode = TransactionCode::factory()->system(SystemTransactionCode::RoomRevenue)->create(['property_id' => $property->id]);
    $tax = Tax::factory()->for($property)->create(['enabled' => true]);
    $tax->applicableTransactionCodes()->attach($roomCode);

    PaymentMethod::factory()->for($property)->create(['type' => PaymentMethodType::Cash]);

    $basicPlan = RatePlan::factory()->for($property)->create(['name' => BasicRatePlans::NAME]);
    $basicPlan->roomTypes()->attach($roomType);

    $from = now(PropertySetting::query()->where('property_id', $property->id)->value('time_zone'))->startOfDay();

    for ($day = 0; $day < 30; $day++) {
        Rate::factory()->create([
            'room_type_id' => $roomType->id,
            'rate_plan_id' => $basicPlan->id,
            'date' => $from->addDays($day)->toDateString(),
            'amount' => '180.00',
        ]);
    }
}

function basicsInput(array $overrides = []): array
{
    return [
        'name' => 'Seaview Guesthouse',
        'type' => PropertyType::Homestay->value,
        'country_code' => 'MY',
        'address_line1' => '12 Jalan Pantai',
        'city' => 'Kuching',
        'licences' => [],
        ...$overrides,
    ];
}
