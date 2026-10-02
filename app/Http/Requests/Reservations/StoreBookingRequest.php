<?php

namespace App\Http\Requests\Reservations;

use App\Data\Reservations\NewAddOnData;
use App\Data\Reservations\NewBookingData;
use App\Data\Reservations\NewBookingGuestData;
use App\Data\Reservations\NewUnitData;
use App\Data\Reservations\UnitPickData;
use App\Enums\BillingMode;
use App\Enums\ReservationSource;
use App\Enums\SellUnit;
use App\Models\Property;
use App\Models\RoomType;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreBookingRequest extends FormRequest
{
    /**
     * The `can:reservations.manage` route middleware authorizes this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Only the shape is checked here, plus the walk-in rule that every unit needs a room or bed. `CreateBooking` and the
     * Actions under it check the stay, restrictions, availability and prices, and refuse with a code.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'source' => ['required', Rule::in([ReservationSource::Direct->value, ReservationSource::Phone->value, ReservationSource::WalkIn->value])],
            'external_reference' => ['nullable', 'string', 'max:100'],
            'arrival_date' => ['required', 'date_format:Y-m-d'],
            'departure_date' => ['required', 'date_format:Y-m-d'],
            'group_name' => ['nullable', 'string', 'max:150'],
            'billing_mode' => ['nullable', Rule::enum(BillingMode::class)],
            'notes' => ['nullable', 'string', 'max:2000'],
            'booker_guest_id' => ['required', 'integer'],
            'lines' => ['required', 'array', 'min:1', 'max:20'],
            'lines.*.room_type_id' => ['required', 'integer'],
            'lines.*.rate_plan_id' => ['required', 'integer'],
            'lines.*.adults' => ['required', 'integer', 'min:1', 'max:99'],
            'lines.*.child_ages' => ['nullable', 'array', 'max:20'],
            'lines.*.child_ages.*' => ['required', 'integer', 'between:0,17'],
            'lines.*.beds' => ['nullable', 'integer', 'min:1', 'max:99'],
            'lines.*.discount_id' => ['nullable', 'integer'],
            'lines.*.override_total' => ['nullable', 'string', 'regex:/^\d+(\.\d{1,2})?$/'],
            'lines.*.override_reason' => ['nullable', 'string', 'max:500'],
            'lines.*.restriction_override_reason' => ['nullable', 'string', 'max:500'],
            'lines.*.add_ons' => ['nullable', 'array', 'max:20'],
            'lines.*.add_ons.*.charge_item_id' => ['required', 'integer'],
            'lines.*.add_ons.*.quantity' => ['nullable', 'integer', 'min:1', 'max:999'],
            'lines.*.add_ons.*.unit_amount' => ['nullable', 'string', 'regex:/^\d+(\.\d{1,2})?$/'],
            'lines.*.add_ons.*.service_date' => ['nullable', 'date_format:Y-m-d'],
            'lines.*.room_id' => ['nullable', 'integer'],
            'lines.*.bed_ids' => ['nullable', 'array', 'max:99'],
            'lines.*.bed_ids.*' => ['nullable', 'integer'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lines.*.override_total.regex' => __('Enter an amount such as 840.00.'),
            'lines.*.add_ons.*.unit_amount.regex' => __('Enter an amount such as 25.00.'),
            'lines.*.child_ages.*.required' => __('Enter the age of every child.'),
            'lines.*.child_ages.*.integer' => __('Enter the age of every child.'),
            'lines.*.child_ages.*.between' => __('A child age must be from 0 to 17.'),
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty() || $this->input('source') !== ReservationSource::WalkIn->value) {
                return;
            }

            foreach ($this->input('lines', []) as $index => $line) {
                if ($this->isBedLine((int) $line['room_type_id'])) {
                    foreach (range(0, max(1, (int) ($line['beds'] ?? 1)) - 1) as $bed) {
                        if (empty($line['bed_ids'][$bed])) {
                            $validator->errors()->add("lines.{$index}.bed_ids.{$bed}", __('Select a bed.'));
                        }
                    }
                } elseif (empty($line['room_id'])) {
                    $validator->errors()->add("lines.{$index}.room_id", __('Select a room.'));
                }
            }
        }];
    }

    public function toBookingData(): NewBookingData
    {
        $arrival = CarbonImmutable::parse($this->validated('arrival_date'));
        $departure = CarbonImmutable::parse($this->validated('departure_date'));
        $units = [];
        $picks = [];
        $unitIndex = 0;

        foreach ($this->validated('lines') as $line) {
            $isBed = $this->isBedLine((int) $line['room_type_id']);
            $beds = $isBed ? max(1, (int) ($line['beds'] ?? 1)) : 1;

            $units[] = new NewUnitData(
                roomTypeId: (int) $line['room_type_id'],
                ratePlanId: (int) $line['rate_plan_id'],
                arrivalDate: $arrival,
                departureDate: $departure,
                adults: (int) $line['adults'],
                childAges: array_map(intval(...), $line['child_ages'] ?? []),
                beds: $beds,
                discountId: isset($line['discount_id']) ? (int) $line['discount_id'] : null,
                overrideTotal: $line['override_total'] ?? null,
                overrideReason: $line['override_reason'] ?? null,
                restrictionOverrideReason: $line['restriction_override_reason'] ?? null,
                addOns: array_map(fn (array $addOn): NewAddOnData => new NewAddOnData(
                    chargeItemId: (int) $addOn['charge_item_id'],
                    quantity: isset($addOn['quantity']) ? (int) $addOn['quantity'] : null,
                    unitAmount: $addOn['unit_amount'] ?? null,
                    serviceDate: isset($addOn['service_date']) ? CarbonImmutable::parse($addOn['service_date']) : null,
                ), $line['add_ons'] ?? []),
            );

            foreach (range(0, $beds - 1) as $bed) {
                $picks[] = $isBed
                    ? new UnitPickData($unitIndex, bedId: isset($line['bed_ids'][$bed]) ? (int) $line['bed_ids'][$bed] : null)
                    : new UnitPickData($unitIndex, roomId: isset($line['room_id']) ? (int) $line['room_id'] : null);
                $unitIndex++;
            }
        }

        return new NewBookingData(
            source: ReservationSource::from($this->validated('source')),
            units: $units,
            guests: [new NewBookingGuestData(guestId: (int) $this->validated('booker_guest_id'))],
            picks: $picks,
            billingMode: BillingMode::tryFrom((string) $this->validated('billing_mode')) ?? BillingMode::MasterPays,
            externalReference: $this->validated('external_reference'),
            groupName: $this->validated('group_name'),
            notes: $this->validated('notes'),
        );
    }

    /**
     * How many units each line adds: the beds of a Bed line, otherwise one. `BookingErrorKeys` uses it to find the line
     * of a unit.
     *
     * @return list<int>
     */
    public function unitsPerLine(): array
    {
        return array_map(
            fn (array $line): int => $this->isBedLine((int) $line['room_type_id']) ? max(1, (int) ($line['beds'] ?? 1)) : 1,
            $this->input('lines', []),
        );
    }

    /**
     * @var array<int, bool>|null
     */
    private ?array $bedTypes = null;

    private function isBedLine(int $roomTypeId): bool
    {
        if ($this->bedTypes === null) {
            /** @var Property $property */
            $property = $this->route('property');
            $this->bedTypes = RoomType::query()
                ->where('property_id', $property->id)
                ->whereIn('id', array_map(fn (array $line): int => (int) ($line['room_type_id'] ?? 0), $this->input('lines', [])))
                ->get(['id', 'sell_unit'])
                ->mapWithKeys(fn (RoomType $roomType): array => [$roomType->id => $roomType->sell_unit === SellUnit::Bed])
                ->all();
        }

        return $this->bedTypes[$roomTypeId] ?? false;
    }
}
