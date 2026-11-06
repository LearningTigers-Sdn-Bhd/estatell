<?php

namespace App\Services\Kiosk;

use App\Enums\PaymentStatus;
use App\Enums\UnitStatus;
use App\Models\KioskWalkInHold;
use App\Models\Payment;
use App\Models\Property;
use App\Models\ReservationUnit;

class KioskWalkInHolds
{
    public function hasPayment(ReservationUnit $stay): bool
    {
        return Payment::query()->where('property_id', $stay->property_id)->where('status', PaymentStatus::Completed)
            ->whereHas('folio', fn ($folios) => $folios->where('property_id', $stay->property_id)->where('reservation_id', $stay->reservation_id))->exists();
    }

    public function expired(Property $property, int $stayId): bool
    {
        $hold = KioskWalkInHold::query()->where('property_id', $property->id)->where('reservation_unit_id', $stayId)
            ->where('expires_at', '<=', now())->with('stay')->first();

        return $hold !== null && in_array($hold->stay->status, [UnitStatus::Confirmed, UnitStatus::Cancelled], true)
            && ! $this->hasPayment($hold->stay);
    }

    public function expiredReference(Property $property, string $reference): bool
    {
        $hold = KioskWalkInHold::query()->where('property_id', $property->id)->where('expires_at', '<=', now())
            ->whereHas('stay.reservation', fn ($reservations) => $reservations->where('property_id', $property->id)->whereRaw('lower(reference) = ?', [mb_strtolower(trim($reference))]))
            ->first();

        return $hold !== null && $this->expired($property, $hold->reservation_unit_id);
    }
}
