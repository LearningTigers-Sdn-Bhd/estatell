<?php

use App\Enums\DefaultRoleEnum;
use App\Enums\PaymentMethodType;
use App\Enums\SystemTransactionCode;
use App\Enums\TaxRateType;
use App\Models\BusinessDate;
use App\Models\ChargeItem;
use App\Models\Folio;
use App\Models\PaymentMethod;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\Tax;
use App\Models\TransactionCode;
use App\Models\User;

/**
 * A live property trading on 2026-10-13 (its first business date is 2026-10-10), an Owner, a reservation created on
 * 2026-10-10 with a checked-in unit and an Open master folio, a Minibar item of 6.00 with an 8% SST, a Laundry item
 * with no tax, a Cash method and the system Refund code.
 *
 * @return array{user: User, property: Property, reservation: Reservation, unit: ReservationUnit, folio: Folio, minibar: ChargeItem, laundry: ChargeItem, sst: Tax, cash: PaymentMethod}
 */
function mfSetup(DefaultRoleEnum $role = DefaultRoleEnum::Owner): array
{
    test()->travelTo(now()->setDate(2026, 10, 13)->setTime(12, 0));
    $user = User::factory()->withRole($role)->create();
    $property = $user->property;
    $property->forceFill(['status' => 'live'])->save();
    PropertySetting::factory()->for($property)->create();
    BusinessDate::factory()->closed()->create(['property_id' => $property->id, 'date' => '2026-10-10']);
    BusinessDate::factory()->closed()->create(['property_id' => $property->id, 'date' => '2026-10-12']);
    BusinessDate::factory()->create(['property_id' => $property->id, 'date' => '2026-10-13']);
    TransactionCode::factory()->system(SystemTransactionCode::Refund)->create(['property_id' => $property->id]);
    $reservation = Reservation::factory()->create(['property_id' => $property->id, 'created_at' => '2026-10-10 09:00:00']);
    $unit = ReservationUnit::factory()->checkedIn()->create(['reservation_id' => $reservation->id]);
    $minibar = ChargeItem::factory()->create(['property_id' => $property->id, 'name' => 'Minibar', 'default_amount' => '6.00', 'allow_override' => true]);
    $laundry = ChargeItem::factory()->create(['property_id' => $property->id, 'name' => 'Laundry', 'default_amount' => '25.00', 'allow_override' => true]);
    $sst = Tax::factory()->for($property)->create(['name' => 'SST', 'rate' => '8.00', 'rate_type' => TaxRateType::Percent, 'is_inclusive' => false]);
    $sst->applicableTransactionCodes()->attach($minibar->transaction_code_id);

    return [
        'user' => $user,
        'property' => $property,
        'reservation' => $reservation,
        'unit' => $unit,
        'folio' => Folio::factory()->master()->create(['property_id' => $property->id, 'reservation_id' => $reservation->id]),
        'minibar' => $minibar,
        'laundry' => $laundry,
        'sst' => $sst,
        'cash' => PaymentMethod::factory()->create(['property_id' => $property->id, 'type' => PaymentMethodType::Cash, 'requires_reference' => false]),
    ];
}
