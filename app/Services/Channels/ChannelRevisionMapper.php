<?php

namespace App\Services\Channels;

use App\Data\Reservations\NewUnitData;
use App\Models\ChannelProperty;
use App\Models\ChannelRatePlanMapping;
use App\Models\OtaChannel;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Throwable;

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

        return array_map(function (array $room, int $index) use ($mappings, $channelName): NewUnitData {
            if (empty($room['room_type_id'])) {
                throw new ChannelRevisionRefused(__('Room :number is not mapped to a room type on the channel.', ['number' => $index + 1]));
            }

            $mapping = empty($room['rate_plan_id']) ? null : $mappings->get($room['rate_plan_id']);

            if ($mapping === null) {
                throw new ChannelRevisionRefused(__('Room :number is not mapped to a rate plan.', ['number' => $index + 1]));
            }

            $occupancy = $room['occupancy'] ?? [];
            $children = (int) ($occupancy['children'] ?? 0);
            $ages = array_values(array_map('intval', is_array($occupancy['ages'] ?? null) ? $occupancy['ages'] : []));

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
        }, $rooms, array_keys($rooms));
    }

    /**
     * The money the guest paid the OTA, when `payment_collect` is `ota`: the booking amount, the amount of each room
     * in the order of the rooms (null when a room has none), and a reference that tells staff how the OTA pays the
     * property. A virtual card gives "Virtual card · charge from {date}" (or "Virtual card" with no date), a bank
     * transfer gives "Bank transfer". No card detail is read. Null when the property collects, or the amount is not
     * above 0.
     *
     * @param  array<string, mixed>  $payload
     * @return array{amount: string, roomAmounts: list<string|null>, reference: string|null}|null
     */
    public function otaPayment(array $payload): ?array
    {
        if (($payload['payment_collect'] ?? null) !== 'ota') {
            return null;
        }

        $amount = $this->money($payload['amount'] ?? null);

        if ($amount === null || ! BigDecimal::of($amount)->isPositive()) {
            return null;
        }

        $rooms = is_array($payload['rooms'] ?? null) ? array_values($payload['rooms']) : [];

        return [
            'amount' => $amount,
            'roomAmounts' => array_map(fn (mixed $room): ?string => is_array($room) ? $this->money($room['amount'] ?? null) : null, $rooms),
            'reference' => $this->otaPaymentReference($payload),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function otaPaymentReference(array $payload): ?string
    {
        $guarantee = is_array($payload['guarantee'] ?? null) ? $payload['guarantee'] : [];

        if (($guarantee['is_virtual'] ?? false) === true) {
            $date = $this->date($guarantee['meta']['virtual_card_effective_date'] ?? null);

            return $date === null ? __('Virtual card') : __('Virtual card · charge from :date', ['date' => $date]);
        }

        return ($payload['payment_type'] ?? null) === 'bank_transfer' ? __('Bank transfer') : null;
    }

    /**
     * A money value from the payload as `0.00`, or null when it is not a number.
     */
    private function money(mixed $value): ?string
    {
        return is_numeric($value) ? (string) BigDecimal::of((string) $value)->toScale(2, RoundingMode::HalfUp) : null;
    }

    /**
     * A date from the payload as `Y-m-d`, or null when it does not read as a date.
     */
    private function date(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->toDateString();
        } catch (Throwable) {
            return null;
        }
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
        $email = filled($customer['mail'] ?? null) ? trim((string) $customer['mail']) : null;
        $phone = filled($customer['phone'] ?? null) ? trim((string) $customer['phone']) : null;

        return [
            'full_name' => $name !== '' ? $name : __('OTA guest'),
            'email' => $this->isOtaSupportEmail($email) ? null : $email,
            'phone' => $this->isOtaSupportPhone($phone) ? null : $phone,
            'country_code' => preg_match('/^[A-Z]{2}$/', $country) === 1 ? $country : null,
        ];
    }

    /**
     * Corporate OTA support mailboxes that belong to the channel, not the guest staying.
     */
    private function isOtaSupportEmail(?string $email): bool
    {
        if (blank($email)) {
            return false;
        }

        $email = mb_strtolower(trim($email));
        $parts = explode('@', $email);

        if (count($parts) !== 2) {
            return false;
        }

        [$user, $domain] = $parts;

        if (in_array($domain, ['trip.com', 'ctrip.com', 'agoda.com', 'booking.com', 'expedia.com', 'airbnb.com'], true)) {
            return true;
        }

        return in_array($user, ['lodgingsupport', 'hotel_support', 'hotelsupport', 'support', 'cs', 'noreply', 'no-reply'], true);
    }

    /**
     * Known OTA call center hotlines and placeholder numbers that do not reach the guest.
     */
    private function isOtaSupportPhone(?string $phone): bool
    {
        if (blank($phone)) {
            return false;
        }

        $digits = preg_replace('/\D+/', '', $phone);

        if (strlen($digits) < 5 || preg_match('/^(\d)\1+$/', $digits) === 1) {
            return true;
        }

        return in_array($digits, [
            '601546000327',
            '442039293737',
            '862152296241',
        ], true);
    }
}
