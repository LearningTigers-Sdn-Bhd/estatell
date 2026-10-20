<?php

namespace App\Services\Channels;

use App\Data\Reservations\NewUnitData;
use App\Models\ChannelProperty;
use App\Models\ChannelRatePlanMapping;
use App\Models\OtaChannel;
use Carbon\CarbonImmutable;

/**
 * Reads a Channex booking revision payload for estatell. It only reads and calculates: it maps rooms to room types and
 * rate plans, picks the channel and works out the guest fields. See docs/21-channels/05-booking-ingestion.md.
 */
class ChannelRevisionMapper
{
    /**
     * One unit line for each room of the revision. A room with no Channex rate plan or room type is an unmapped one.
     * The room amount is the stay total of the unit, stored as the override total.
     *
     * @param  array<string, mixed>  $payload
     * @return list<NewUnitData>
     *
     * @throws ChannelRevisionRefused
     */
    public function units(ChannelProperty $channelProperty, array $payload, string $channelName): array
    {
        $rooms = $payload['rooms'] ?? [];

        if (! is_array($rooms) || $rooms === []) {
            throw new ChannelRevisionRefused(__('The booking has no rooms.'));
        }

        $mappings = ChannelRatePlanMapping::query()
            ->where('channel_property_id', $channelProperty->id)
            ->whereIn('external_rate_plan_id', array_values(array_filter(array_column($rooms, 'rate_plan_id'))))
            ->get()
            ->keyBy('external_rate_plan_id');

        return array_values(array_map(function (array $room, int $index) use ($mappings, $channelName): NewUnitData {
            if (empty($room['room_type_id'])) {
                throw new ChannelRevisionRefused(__('Room :number is not mapped to a room type on the channel.', ['number' => $index + 1]));
            }

            $mapping = empty($room['rate_plan_id']) ? null : $mappings->get($room['rate_plan_id']);

            if ($mapping === null) {
                throw new ChannelRevisionRefused(__('Room :number is not mapped to a rate plan.', ['number' => $index + 1]));
            }

            $occupancy = $room['occupancy'] ?? [];
            $children = (int) ($occupancy['children'] ?? 0);
            $ages = array_map('intval', is_array($occupancy['ages'] ?? null) ? $occupancy['ages'] : []);

            return new NewUnitData(
                roomTypeId: $mapping->room_type_id,
                ratePlanId: $mapping->rate_plan_id,
                arrivalDate: CarbonImmutable::parse((string) $room['checkin_date'])->startOfDay(),
                departureDate: CarbonImmutable::parse((string) $room['checkout_date'])->startOfDay(),
                adults: max(1, (int) ($occupancy['adults'] ?? 1)),
                childAges: count($ages) === $children ? $ages : array_fill(0, $children, 5),
                overrideTotal: number_format((float) ($room['amount'] ?? 0), 2, '.', ''),
                overrideReason: __('Booked on :channel', ['channel' => $channelName]),
            );
        }, $rooms, array_keys($rooms)));
    }

    /**
     * The estatell channel for the OTA name in the revision, or the Other row with that name.
     */
    public function channel(string $otaName): OtaChannel
    {
        $channel = OtaChannel::query()->where('is_other', false)->whereRaw('lower(name) = ?', [mb_strtolower($otaName)])->first();

        return $channel ?? OtaChannel::query()->where('is_other', true)->firstOrFail();
    }

    /**
     * The name shown for the channel: the channel's own name, or the OTA name from the revision on Other.
     */
    public function channelName(OtaChannel $channel, string $otaName): string
    {
        return $channel->is_other ? trim($otaName) : $channel->name;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{full_name: string, email: string|null, phone: string|null, country_code: string|null}
     */
    public function guest(array $payload): array
    {
        $customer = $payload['customer'] ?? [];
        $name = trim(trim((string) ($customer['name'] ?? '')).' '.trim((string) ($customer['surname'] ?? '')));
        $country = strtoupper((string) ($customer['country'] ?? ''));

        return [
            'full_name' => $name !== '' ? $name : __('OTA guest'),
            'email' => filled($customer['mail'] ?? null) ? trim((string) $customer['mail']) : null,
            'phone' => filled($customer['phone'] ?? null) ? trim((string) $customer['phone']) : null,
            'country_code' => preg_match('/^[A-Z]{2}$/', $country) === 1 ? $country : null,
        ];
    }
}
