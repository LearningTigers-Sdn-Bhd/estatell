<?php

namespace App\Services\NightAudit;

use App\Actions\NightAudit\RetryRoomCharge;
use App\Enums\AuditExceptionType;
use App\Enums\PricingBasis;
use App\Models\FolioLine;
use App\Models\NightAuditException;
use App\Models\UnitNight;
use App\Services\Packages\InclusionSplit;
use App\Services\Rates\Pricing\PackagePricing;
use App\Services\Rates\Pricing\QuoteRefused;
use Brick\Money\Money;
use Illuminate\Database\Eloquent\Builder;

class PackageNightRespread
{
    public const REFUSAL_NOT_PACKAGE_NIGHT = 'not_package_night';

    public function __construct(private PackagePricing $packagePricing) {}

    /**
     * Spread the stored prices of the uncharged nights. A room line identifies a charged night, but its amount is
     * never used: the night posts its inclusions separately. When the uncharged nights cannot cover their inclusions,
     * the whole stay is spread again instead (`wholeStay`), and the charged nights are corrected by the fix. The
     * preview and the fix share this read-only calculation.
     */
    public function forException(NightAuditException $exception): PackageNightRespreadResult
    {
        if ($exception->resolved_at !== null || $exception->type !== AuditExceptionType::RoomCharge || $exception->reservationUnit === null) {
            return new PackageNightRespreadResult(refusal: RetryRoomCharge::REFUSAL_EXCEPTION_NOT_RETRIED);
        }

        $unit = $exception->reservationUnit;
        $nights = $unit->nights()->with('ratePlan.inclusions')->orderBy('date')->get();
        $date = $exception->nightAudit->businessDate->date->toDateString();
        $target = $nights->first(fn (UnitNight $night): bool => $night->date->toDateString() === $date);

        if ($exception->message !== InclusionSplit::REFUSAL_EXCEEDS_NIGHT_AMOUNT || $target?->ratePlan->pricing_basis !== PricingBasis::Package) {
            return new PackageNightRespreadResult(refusal: self::REFUSAL_NOT_PACKAGE_NIGHT);
        }

        $keys = FolioLine::query()->whereHas('folio', fn (Builder $folio) => $folio->where('property_id', $exception->property_id))
            ->whereIn('idempotency_key', $nights->map(fn (UnitNight $night): string => 'night:'.$night->id))
            ->pluck('idempotency_key')->all();
        $charged = $nights->filter(fn (UnitNight $night): bool => in_array('night:'.$night->id, $keys, true));
        $unposted = $nights->reject(fn (UnitNight $night): bool => in_array('night:'.$night->id, $keys, true))->values()->all();
        $currency = $unit->property->settings->currency->value;
        $total = Money::sum(...$nights->map(fn (UnitNight $night): Money => Money::of($night->amount, $currency))->all());
        $remaining = array_reduce($unposted, fn (Money $sum, UnitNight $night): Money => $sum->plus($night->amount), Money::zero($currency));

        $inclusions = $target->ratePlan->inclusions;
        $guests = $unit->adults + $unit->children;
        $firstNightId = $nights->first()->id;

        try {
            $amounts = $this->packagePricing->spreadByInclusions(
                $remaining,
                array_map(fn (UnitNight $night): bool => $night->id === $firstNightId, $unposted),
                $inclusions,
                $guests,
            );

            return new PackageNightRespreadResult($unposted, $amounts, $charged->values()->all(), $total, $remaining);
        } catch (QuoteRefused $refused) {
            if ($charged->isEmpty()) {
                return new PackageNightRespreadResult(total: $total, remaining: $remaining, refusal: $refused->reason);
            }
        }

        try {
            $amounts = $this->packagePricing->spreadByInclusions(
                $total,
                $nights->map(fn (UnitNight $night): bool => $night->id === $firstNightId)->all(),
                $inclusions,
                $guests,
            );
        } catch (QuoteRefused $refused) {
            return new PackageNightRespreadResult(total: $total, remaining: $total, refusal: $refused->reason);
        }

        return new PackageNightRespreadResult(
            $nights->all(),
            $amounts,
            total: $total,
            remaining: $total,
            wholeStay: true,
            chargedNightIds: $charged->pluck('id')->values()->all(),
        );
    }
}
