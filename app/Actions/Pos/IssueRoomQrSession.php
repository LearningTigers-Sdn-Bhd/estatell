<?php

namespace App\Actions\Pos;

use App\Enums\PosQrSessionKind;
use App\Enums\UnitStatus;
use App\Features\Pos;
use App\Models\PosQrSession;
use App\Models\Property;
use App\Models\ReservationUnit;
use App\Services\Pos\PosPinHasher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;

/**
 * Give an in-house unit its room QR: a random token and a new 4-digit stay PIN. Any earlier room token of the unit is revoked.
 * Returns the plain PIN, which is shown once and never stored. Returns null when the unit is not checked in, or when the
 * property does not use the point of sale, so check-in never depends on POS. Run on check-in (`StayCheckedIn`) and by
 * front desk when the PIN is lost. See docs/34-native-pos/06-kitchen-kds-and-qr.md.
 */
class IssueRoomQrSession
{
    public function __construct(private PosPinHasher $hasher) {}

    public function __invoke(int $propertyId, int $reservationUnitId): ?string
    {
        $property = Property::query()->find($propertyId);

        if ($property === null || ! Feature::for($property)->active(Pos::class)) {
            return null;
        }

        return DB::transaction(function () use ($propertyId, $reservationUnitId): ?string {
            $unit = ReservationUnit::query()->where('property_id', $propertyId)->whereKey($reservationUnitId)->lockForUpdate()->first();

            if ($unit === null || $unit->status !== UnitStatus::CheckedIn) {
                return null;
            }

            PosQrSession::query()
                ->where('property_id', $propertyId)
                ->where('kind', PosQrSessionKind::Room->value)
                ->where('reservation_unit_id', $unit->id)
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);

            $pin = str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);

            $session = new PosQrSession([
                'kind' => PosQrSessionKind::Room,
                'token' => Str::random(32),
                'stay_pin_hash' => $this->hasher->make($pin),
                'single_use' => false,
            ]);
            $session->property_id = $propertyId;
            $session->reservation_unit_id = $unit->id;
            $session->expires_at = $unit->departure_date->endOfDay();
            $session->save();

            return $pin;
        });
    }
}
