<?php

use App\Actions\Events\CreateEvent;
use App\Enums\EventStatus;
use App\Enums\PaymentMethodType;
use App\Enums\SystemTransactionCode;
use App\Features\Facilities;
use App\Models\BusinessDate;
use App\Models\ChargeItem;
use App\Models\Event;
use App\Models\Folio;
use App\Models\Guest;
use App\Models\PaymentMethod;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\Tax;
use App\Models\TransactionCode;
use App\Models\User;
use Laravel\Pennant\Feature;

/**
 * A live property with the Facilities feature, an open business date (the frozen test day, 14 Oct 2026), the system
 * Refund code, an owner, a Cash method and a Hall hire charge item of 1,000.00 with an 8% exclusive tax.
 *
 * @return array{property: Property, owner: User, cash: PaymentMethod, hall: ChargeItem}
 */
function eventSetup(): array
{
    $property = Property::factory()->live()->create();
    PropertySetting::factory()->for($property)->create(['currency' => 'MYR']);
    BusinessDate::factory()->create(['property_id' => $property->id]);
    TransactionCode::factory()->system(SystemTransactionCode::Refund)->create(['property_id' => $property->id]);
    Feature::for($property)->activate(Facilities::class);

    $hall = ChargeItem::factory()->create(['property_id' => $property->id, 'name' => 'Hall hire', 'default_amount' => '1000.00', 'allow_override' => true]);
    $tax = Tax::factory()->for($property)->create(['rate' => '8.00', 'is_inclusive' => false]);
    $tax->applicableTransactionCodes()->attach($hall->transaction_code_id);

    return [
        'property' => $property,
        'owner' => User::factory()->owner()->create(['property_id' => $property->id]),
        'cash' => PaymentMethod::factory()->create(['property_id' => $property->id, 'type' => PaymentMethodType::Cash, 'requires_reference' => false]),
        'hall' => $hall,
    ];
}

/**
 * An event made by `CreateEvent`, as staff make it: a guest payer, 20 Oct 2026 (after the business date) and its
 * Open Event folio.
 *
 * @param  array<string, mixed>  $attributes
 * @return array{0: Event, 1: Folio}
 */
function makeEvent(Property $property, User $actor, array $attributes = []): array
{
    $event = app(CreateEvent::class)($property, $actor, [
        'name' => 'Lim & Tan wedding dinner',
        'payer_guest_id' => Guest::factory()->create(['property_id' => $property->id])->id,
        'payer_company_id' => null,
        'starts_on' => '2026-10-20',
        'ends_on' => '2026-10-20',
        'status' => EventStatus::Confirmed,
        ...$attributes,
    ]);

    return [$event, Folio::query()->where('event_id', $event->id)->sole()];
}
