<?php

namespace App\Services\Reservations;

use App\Data\Folio\FolioDiscountOptionData;
use App\Data\Payments\PaymentFormOptionsData;
use App\Data\Payments\PaymentMethodOptionData;
use App\Data\Reservations\AddUnitOptionsData;
use App\Data\Reservations\ChargeCodeOptionData;
use App\Data\Reservations\ChargeItemOptionData;
use App\Data\Reservations\DiscountOptionData;
use App\Data\Reservations\RatePlanOptionData;
use App\Data\Reservations\RoomTypeOptionData;
use App\Enums\DiscountScope;
use App\Enums\DiscountType;
use App\Enums\PaymentMethodType;
use App\Enums\TransactionKind;
use App\Models\ChargeItem;
use App\Models\Discount;
use App\Models\PaymentMethod;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\RoomType;
use App\Models\TransactionCode;

/**
 * The lists the reservation page forms pick from. Each list is read when its overlay opens, in two queries or fewer.
 */
class ReservationFormOptions
{
    public function addUnit(Property $property): AddUnitOptionsData
    {
        return new AddUnitOptionsData(
            roomTypes: RoomType::query()->where('property_id', $property->id)->active()->ordered()->get()
                ->map(RoomTypeOptionData::fromModel(...))->all(),
            ratePlans: RatePlan::query()->where('property_id', $property->id)->active()->ordered()->with('roomTypes:id')->get()
                ->map(RatePlanOptionData::fromModel(...))->all(),
        );
    }

    /**
     * The active RoomOnly discounts, the only kind a booking line may carry.
     *
     * @return list<DiscountOptionData>
     */
    public function roomDiscounts(Property $property): array
    {
        return Discount::query()->where('property_id', $property->id)->active()->where('scope', DiscountScope::RoomOnly)->ordered()->get()
            ->map(DiscountOptionData::fromModel(...))->all();
    }

    /**
     * @return list<ChargeItemOptionData>
     */
    public function chargeItems(Property $property): array
    {
        return ChargeItem::query()->where('property_id', $property->id)->active()->ordered()->get()
            ->map(ChargeItemOptionData::fromModel(...))->all();
    }

    /**
     * The active charge codes, for a charge posted with an open amount.
     *
     * @return list<ChargeCodeOptionData>
     */
    public function chargeCodes(Property $property): array
    {
        return TransactionCode::query()->where('property_id', $property->id)->where('kind', TransactionKind::Charge)->active()->orderBy('code')->get()
            ->map(ChargeCodeOptionData::fromModel(...))->all();
    }

    /**
     * The active discounts a folio can take. A user without `folio.discount-open` gets no Open discount and cannot
     * type an amount.
     *
     * @return list<FolioDiscountOptionData>
     */
    public function folioDiscounts(Property $property, bool $canEnterOpenAmounts): array
    {
        return Discount::query()->where('property_id', $property->id)->active()->ordered()->get()
            ->reject(fn (Discount $discount): bool => ! $canEnterOpenAmounts && $discount->type === DiscountType::Open)
            ->map(fn (Discount $discount): FolioDiscountOptionData => FolioDiscountOptionData::fromModel($discount, $canEnterOpenAmounts))
            ->values()
            ->all();
    }

    /**
     * The methods and purposes of the Record payment Dialog. City ledger and OTA collected methods are not offered
     * yet. `RecordPayment` checks the method again.
     */
    public function payments(Property $property): PaymentFormOptionsData
    {
        return PaymentFormOptionsData::forMethods(
            PaymentMethod::query()->where('property_id', $property->id)->active()
                ->whereNotIn('type', [PaymentMethodType::OtaCollected, PaymentMethodType::DirectBill])
                ->ordered()->get()
                ->map(PaymentMethodOptionData::fromModel(...))->all(),
        );
    }
}
