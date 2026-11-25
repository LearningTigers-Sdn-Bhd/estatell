<?php

namespace App\Services\Channels;

use App\Data\Reservations\NewUnitData;
use App\Enums\SellUnit;
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
        $rooms = $this->rooms($channelProperty, $payload);

        if ($rooms === []) {
            throw new ChannelRevisionRefused(__('The booking has no rooms.'));
        }

        $mappings = ChannelRatePlanMapping::query()
            ->where('channel_property_id', $channelProperty->id)
            ->whereIn('external_rate_plan_id', array_values(array_filter(array_column($rooms, 'rate_plan_id'))))
            ->with('roomType')
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
            $infants = max(0, (int) ($occupancy['infants'] ?? 0));
            $ages = array_values(array_map('intval', is_array($occupancy['ages'] ?? null) ? $occupancy['ages'] : []));
            // A child with no age counts as 5 (the child price); an infant as 0, so it is free and still on the manifest.
            $childAges = [...(count($ages) === $children ? $ages : array_fill(0, $children, 5)), ...array_fill(0, $infants, 0)];
            // A dorm goes to the channel one bed per room for one guest (BedAsUnit); `rooms()` has split a room with more
            // guests into one room per guest, so each room here is one bed.
            $isBed = $mapping->roomType->sell_unit === SellUnit::Bed;

            return new NewUnitData(
                roomTypeId: $mapping->room_type_id,
                ratePlanId: $mapping->rate_plan_id,
                arrivalDate: CarbonImmutable::parse((string) $room['checkin_date'])->startOfDay(),
                departureDate: CarbonImmutable::parse((string) $room['checkout_date'])->startOfDay(),
                adults: $isBed ? 1 : max(1, (int) ($occupancy['adults'] ?? 1)),
                childAges: $isBed ? [] : $childAges,
                overrideTotal: number_format((float) ($room['amount'] ?? 0), 2, '.', ''),
                overrideReason: __('Booked on :channel', ['channel' => $channelName]),
            );
        }, $rooms, array_keys($rooms));
    }

    /**
     * The rooms of the revision, with each room of a dorm (a rate plan mapped to a Bed room type) split into one room
     * per guest: an OTA may send three backpackers as one room with three adults, while BedAsUnit sells one bed per
     * room. Adults and children each take a bed; infants share one. Each bed gets one guest name, when the room has
     * it, and an equal share of the amount and of each night's price, a cent more on the first beds until it adds up.
     * Every other room is returned as it is, in order. `units()`, a modification and the OTA payment shares all read
     * this list, so a room keeps its place across revisions. See docs/21-channels/03-dorm-strategies.md.
     *
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    public function rooms(ChannelProperty $channelProperty, array $payload): array
    {
        $rooms = is_array($payload['rooms'] ?? null) ? array_values(array_filter($payload['rooms'], is_array(...))) : [];
        $bedPlans = ChannelRatePlanMapping::query()
            ->where('channel_property_id', $channelProperty->id)
            ->whereIn('external_rate_plan_id', array_values(array_filter(array_column($rooms, 'rate_plan_id'))))
            ->whereHas('roomType', fn ($query) => $query->where('sell_unit', SellUnit::Bed->value))
            ->pluck('external_rate_plan_id')
            ->all();
        $split = [];

        foreach ($rooms as $room) {
            $occupancy = is_array($room['occupancy'] ?? null) ? $room['occupancy'] : [];
            $beds = max(1, (int) ($occupancy['adults'] ?? 1) + (int) ($occupancy['children'] ?? 0));

            if ($beds === 1 || ! in_array($room['rate_plan_id'] ?? null, $bedPlans, true)) {
                $split[] = $room;

                continue;
            }

            $guests = is_array($room['guests'] ?? null) ? array_values($room['guests']) : [];
            $amounts = $this->shares($room['amount'] ?? null, $beds);
            $days = [];

            foreach (is_array($room['days'] ?? null) ? $room['days'] : [] as $date => $price) {
                $days[$date] = $this->shares($price, $beds);
            }

            for ($bed = 0; $bed < $beds; $bed++) {
                $split[] = [
                    ...$room,
                    'occupancy' => ['adults' => 1, 'children' => 0, 'infants' => 0, 'ages' => []],
                    'guests' => isset($guests[$bed]) ? [$guests[$bed]] : [],
                    'amount' => $amounts === null ? ($room['amount'] ?? null) : $amounts[$bed],
                    'days' => array_map(fn (?array $shares): mixed => $shares[$bed] ?? null, $days),
                ];
            }
        }

        return $split;
    }

    /**
     * An amount split into `$parts` equal two-decimal shares that add up to it, a cent more on the first shares. Null
     * when the amount does not read as a number.
     *
     * @return list<string>|null
     */
    private function shares(mixed $amount, int $parts): ?array
    {
        if (! is_numeric($amount)) {
            return null;
        }

        $cents = BigDecimal::of((string) $amount)->multipliedBy(100)->toScale(0, RoundingMode::HalfUp)->toBigInteger()->toInt();
        $base = intdiv($cents, $parts);
        $extra = $cents - $base * $parts;

        return array_map(fn (int $part): string => number_format(($base + ($part < $extra ? 1 : 0)) / 100, 2, '.', ''), range(0, $parts - 1));
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
    public function otaPayment(array $payload, ?ChannelProperty $channelProperty = null): ?array
    {
        if (($payload['payment_collect'] ?? null) !== 'ota') {
            return null;
        }

        $amount = $this->money($payload['amount'] ?? null);

        if ($amount === null || ! BigDecimal::of($amount)->isPositive()) {
            return null;
        }

        $rooms = $channelProperty === null ? (is_array($payload['rooms'] ?? null) ? array_values($payload['rooms']) : []) : $this->rooms($channelProperty, $payload);

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
