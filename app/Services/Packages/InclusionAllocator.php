<?php

namespace App\Services\Packages;

use App\Enums\ChargingUnit;
use App\Models\RatePlanInclusion;
use Brick\Math\RoundingMode;
use Brick\Money\Currency;
use Brick\Money\Money;

class InclusionAllocator
{
    /**
     * Split one night's amount into the room and its inclusion lines. Read only.
     *
     * A line is the inclusion's allocated amount x quantity x units. PerNight and PerGuestNight post every night.
     * PerItem, PerStay and PerGuest post once, on the first night. PerGuestNight and PerGuest count the guests.
     * The room keeps what is left. The inclusion dates do not matter here, they set the meals a unit is owed.
     *
     * The split is refused when there are no guests, or when the lines add up to more than the night.
     *
     * @param  iterable<RatePlanInclusion>  $inclusions
     */
    public function allocate(Money $nightAmount, iterable $inclusions, int $guests, bool $firstNight): InclusionSplit
    {
        if ($guests < 1) {
            return InclusionSplit::refused(InclusionSplit::REFUSAL_GUESTS_REQUIRED);
        }

        $lines = $this->lines($nightAmount->getCurrency(), $inclusions, $guests, $firstNight);
        $room = array_reduce($lines, fn (Money $room, InclusionLine $line): Money => $room->minus($line->amount), $nightAmount);

        if ($room->isNegative()) {
            return InclusionSplit::refused(InclusionSplit::REFUSAL_EXCEEDS_NIGHT_AMOUNT);
        }

        return InclusionSplit::split($room, $lines);
    }

    /**
     * The sum of one night's inclusion lines, with the same rules as `allocate()`. Read only.
     *
     * @param  iterable<RatePlanInclusion>  $inclusions
     */
    public function total(Currency $currency, iterable $inclusions, int $guests, bool $firstNight): Money
    {
        return array_reduce(
            $this->lines($currency, $inclusions, $guests, $firstNight),
            fn (Money $total, InclusionLine $line): Money => $total->plus($line->amount),
            Money::zero($currency),
        );
    }

    /**
     * @param  iterable<RatePlanInclusion>  $inclusions
     * @return list<InclusionLine>
     */
    private function lines(Currency $currency, iterable $inclusions, int $guests, bool $firstNight): array
    {
        $lines = [];

        foreach ($inclusions as $inclusion) {
            if (! $firstNight && $this->postsOnce($inclusion->charging_unit)) {
                continue;
            }

            $amount = Money::of($inclusion->allocated_amount, $currency, roundingMode: RoundingMode::HalfUp)
                ->multipliedBy($inclusion->quantity * $this->units($inclusion->charging_unit, $guests));

            if ($amount->isZero()) {
                continue;
            }

            $lines[] = new InclusionLine($inclusion->id, $inclusion->charge_item_id, $amount);
        }

        return $lines;
    }

    private function postsOnce(ChargingUnit $unit): bool
    {
        return match ($unit) {
            ChargingUnit::PerNight, ChargingUnit::PerGuestNight => false,
            ChargingUnit::PerItem, ChargingUnit::PerStay, ChargingUnit::PerGuest => true,
        };
    }

    private function units(ChargingUnit $unit, int $guests): int
    {
        return match ($unit) {
            ChargingUnit::PerGuestNight, ChargingUnit::PerGuest => $guests,
            ChargingUnit::PerItem, ChargingUnit::PerNight, ChargingUnit::PerStay => 1,
        };
    }
}
