<?php

namespace App\Services\Channels\Channex;

use App\Enums\ChannelSellMode;
use App\Enums\ChannelSupport;
use App\Enums\Weekday;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\RoomType;
use App\Services\Channels\ChannelCapability;
use App\Services\Channels\ChannelCapabilityResult;
use Illuminate\Support\Str;

/**
 * Builds the Channex property, room type and rate plan bodies. Ids that Channex owns (property_id, room_type_id) are
 * added by the driver once they are known. Every plan is sent as an independent manual plan: estatell works out prices.
 */
class ChannexStructureMapper
{
    /** Channex weekday arrays, Monday first (to verify on staging). */
    private const WEEKDAYS = [Weekday::Monday, Weekday::Tuesday, Weekday::Wednesday, Weekday::Thursday, Weekday::Friday, Weekday::Saturday, Weekday::Sunday];

    public function __construct(private ChannelCapability $capability) {}

    /**
     * @return array<string, mixed>
     */
    public function property(Property $property): array
    {
        $settings = $property->settings;

        return array_filter([
            'title' => $property->name,
            'currency' => $settings->currency->getCurrencyCode(),
            'timezone' => $settings->time_zone,
            'property_type' => $property->type?->channexType(),
            'country' => $property->country_code,
            'state' => $property->state_code,
            'city' => $property->city,
            'address' => $this->address($property),
            'zip_code' => $property->postal_code,
            'email' => $property->contact_email,
            'phone' => $property->contact_phone === null ? null : Str::limit($property->contact_phone, 32, ''),
            'group_id' => config('services.channex.group_id'),
        ], fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @return array<string, mixed>
     */
    public function roomType(RoomType $roomType, int $activeRooms): array
    {
        return [
            'title' => Str::limit($roomType->name, 255, ''),
            'count_of_rooms' => $activeRooms,
            'occ_adults' => $roomType->max_adults,
            'occ_children' => $roomType->max_children,
            'occ_infants' => 0,
            'default_occupancy' => min($roomType->base_occupancy, $roomType->max_adults),
            'room_kind' => 'room',
            'content' => ['description' => $roomType->description],
        ];
    }

    /**
     * The Channex title is unique per property, so it joins the plan and the room type.
     */
    public function ratePlanTitle(RatePlan $ratePlan, RoomType $roomType): string
    {
        return Str::limit("{$ratePlan->name} · {$roomType->name}", 255, '');
    }

    /**
     * @return array<string, mixed>
     */
    public function ratePlan(RatePlan $ratePlan, RoomType $roomType, ChannelCapabilityResult $result, string $currency): array
    {
        $sellMode = $this->capability->sellMode($ratePlan, $roomType);
        $nights = $result->support === ChannelSupport::Flattened ? (int) $ratePlan->fixed_nights : null;

        return [
            'title' => $this->ratePlanTitle($ratePlan, $roomType),
            'currency' => $currency,
            'sell_mode' => $sellMode->value,
            'rate_mode' => 'manual',
            'options' => $this->options($roomType, $sellMode),
            'children_fee' => $this->capability->childFee($ratePlan),
            'infant_fee' => '0.00',
            'min_stay_arrival' => array_fill(0, 7, $nights ?? 1),
            'min_stay_through' => array_fill(0, 7, $nights ?? 1),
            'max_stay' => array_fill(0, 7, $nights ?? 0),
            'closed_to_arrival' => $this->closedToArrival($ratePlan, $nights !== null),
            'closed_to_departure' => array_fill(0, 7, false),
            'stop_sell' => array_fill(0, 7, false),
        ];
    }

    /**
     * Per room: one option at the top occupancy. Per person (a per-guest plan, or a per-room plan whose price depends on the adults): one option for each adult count, the top one primary.
     * Prices are sent with availability and rates, so each option starts at 0.
     *
     * @return list<array{occupancy: int, is_primary: bool, rate: int}>
     */
    private function options(RoomType $roomType, ChannelSellMode $sellMode): array
    {
        $occupancies = $sellMode === ChannelSellMode::PerPerson ? range(1, $roomType->max_adults) : [$roomType->max_adults];

        return array_map(
            fn (int $occupancy): array => ['occupancy' => $occupancy, 'is_primary' => $occupancy === $roomType->max_adults, 'rate' => 0],
            $occupancies,
        );
    }

    /**
     * @return list<bool>
     */
    private function closedToArrival(RatePlan $ratePlan, bool $isPackage): array
    {
        $allowed = $isPackage ? $this->capability->allowedArrivalDays($ratePlan) : null;

        return array_map(
            fn (Weekday $day): bool => $allowed !== null && ! in_array($day, $allowed, true),
            self::WEEKDAYS,
        );
    }

    private function address(Property $property): ?string
    {
        $address = trim(implode(', ', array_filter([$property->address_line1, $property->address_line2])));

        return $address === '' ? null : Str::limit($address, 255, '');
    }
}
