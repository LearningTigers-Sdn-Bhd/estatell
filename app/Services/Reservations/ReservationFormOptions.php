<?php

namespace App\Services\Reservations;

use App\Data\Boats\BoatDepartureOptionData;
use App\Data\Folio\FolioDiscountOptionData;
use App\Data\Payments\PaymentFormOptionsData;
use App\Data\Payments\PaymentMethodOptionData;
use App\Data\Reservations\AddUnitOptionsData;
use App\Data\Reservations\ChargeCodeOptionData;
use App\Data\Reservations\ChargeItemOptionData;
use App\Data\Reservations\DiscountOptionData;
use App\Data\Reservations\RatePlanOptionData;
use App\Data\Reservations\RoomTypeOptionData;
use App\Enums\CompanyAccess;
use App\Enums\DiscountScope;
use App\Enums\DiscountType;
use App\Enums\PaymentMethodType;
use App\Enums\PricingBasis;
use App\Enums\TransactionKind;
use App\Models\BoatDeparture;
use App\Models\ChargeItem;
use App\Models\Company;
use App\Models\Discount;
use App\Models\PaymentMethod;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\RoomType;
use App\Models\TransactionCode;
use App\Models\User;
use App\Services\NightAudit\CurrentBusinessDate;
use App\Services\Payments\AvailablePaymentMethod;
use Illuminate\Support\Collection;

/**
 * The lists the reservation page forms pick from. Each list is read when its overlay opens, in two queries or fewer.
 */
class ReservationFormOptions
{
    public function addUnit(Property $property): AddUnitOptionsData
    {
        $companyIdsByMarket = Company::query()->where('property_id', $property->id)->whereNotNull('market')
            ->get(['id', 'market'])
            ->groupBy(fn (Company $company): string => $company->market->value)
            ->map(fn ($companies): array => array_values($companies->pluck('id')->map(intval(...))->all()))
            ->all();

        $plans = RatePlan::query()->where('property_id', $property->id)->active()->ordered()->with('roomTypes:id', 'companies:id')->get();

        return new AddUnitOptionsData(
            roomTypes: array_values(RoomType::query()->where('property_id', $property->id)->active()->ordered()->get()
                ->map(fn (RoomType $roomType): RoomTypeOptionData => RoomTypeOptionData::fromModel($roomType, $this->enteredPlanId($plans, $roomType)))->all()),
            ratePlans: array_values($plans
                ->map(fn (RatePlan $ratePlan): RatePlanOptionData => RatePlanOptionData::fromModel($ratePlan, $companyIdsByMarket[$ratePlan->company_market->value] ?? []))->all()),
        );
    }

    /**
     * The first per-night plan (Per room or Per guest, open to everyone) of the room type by position, or null.
     *
     * @param  Collection<int, RatePlan>  $plans  Ordered by position, with `roomTypes` loaded.
     */
    private function enteredPlanId(Collection $plans, RoomType $roomType): ?int
    {
        return $plans->first(fn (RatePlan $plan): bool => in_array($plan->pricing_basis, [PricingBasis::PerRoom, PricingBasis::PerGuest], true)
            && $plan->company_access === CompanyAccess::Everyone
            && $plan->roomTypes->contains('id', $roomType->id))?->id;
    }

    /**
     * The active RoomOnly discounts, the only kind a booking line may carry.
     *
     * @return list<DiscountOptionData>
     */
    public function roomDiscounts(Property $property): array
    {
        return array_values(Discount::query()->where('property_id', $property->id)->active()->where('scope', DiscountScope::RoomOnly)->ordered()->get()
            ->map(DiscountOptionData::fromModel(...))->all());
    }

    /**
     * @return list<ChargeItemOptionData>
     */
    public function chargeItems(Property $property): array
    {
        return array_values(ChargeItem::query()->where('property_id', $property->id)->active()->ordered()->get()
            ->map(ChargeItemOptionData::fromModel(...))->all());
    }

    /**
     * The active boat departures the booking form picks from. Bounded: a few rows a day.
     *
     * @return list<BoatDepartureOptionData>
     */
    public function boatDepartures(Property $property): array
    {
        return array_values(BoatDeparture::query()->where('property_id', $property->id)->where('active', true)->ordered()->get()
            ->map(BoatDepartureOptionData::fromModel(...))->all());
    }

    /**
     * The active charge codes, for a charge posted with an open amount.
     *
     * @return list<ChargeCodeOptionData>
     */
    public function chargeCodes(Property $property): array
    {
        return array_values(TransactionCode::query()->where('property_id', $property->id)->where('kind', TransactionKind::Charge)->active()->orderBy('code')->get()
            ->map(ChargeCodeOptionData::fromModel(...))->all());
    }

    /**
     * The active discounts a folio can take. A user without `folio.discount-open` gets no Open discount and cannot
     * type an amount.
     *
     * @return list<FolioDiscountOptionData>
     */
    public function folioDiscounts(Property $property, bool $canEnterOpenAmounts): array
    {
        return array_values(Discount::query()->where('property_id', $property->id)->active()->ordered()->get()
            ->reject(fn (Discount $discount): bool => ! $canEnterOpenAmounts && $discount->type === DiscountType::Open)
            ->map(fn (Discount $discount): FolioDiscountOptionData => FolioDiscountOptionData::fromModel($discount, $canEnterOpenAmounts))
            ->values()
            ->all());
    }

    /**
     * The methods of the Record payment Dialog. City ledger is not offered. An active OTA collected method is, for an
     * OTA booking the OTA was paid for; check-in and check-out collection leave it out (`isOtaCollected`). The Old system method is added, at the end, only for a user with `folio.manual` while the migration window
     * is open, whatever its `active` flag says. `RecordPayment` checks the method again.
     */
    public function payments(Property $property, ?User $user = null): PaymentFormOptionsData
    {
        $methods = PaymentMethod::query()->where('property_id', $property->id)
            ->where(function ($query) use ($property, $user): void {
                $query->where(fn ($active) => $active->active()->whereNotIn('type', [PaymentMethodType::DirectBill, PaymentMethodType::OldSystem]));

                if ($user !== null && app(AvailablePaymentMethod::class)->oldSystemOffered($property, $user)) {
                    $query->orWhere('type', PaymentMethodType::OldSystem);
                }
            })
            ->ordered()->get();

        return PaymentFormOptionsData::forMethods(
            array_values($methods->map(PaymentMethodOptionData::fromModel(...))->all()),
            app(CurrentBusinessDate::class)->date($property)?->toDateString(),
        );
    }
}
