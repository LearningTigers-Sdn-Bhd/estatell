<?php

namespace App\Actions\Registration;

use App\Enums\RegistrationCardStatus;
use App\Models\RegistrationCard;
use App\Models\UnitGuest;
use App\Services\Registration\RegistrationCardRequirement;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class PrepareRegistrationCard
{
    public function __construct(private RegistrationCardRequirement $requirement) {}

    /**
     * Find the open card of a unit guest, or start a draft. A Signed card is returned as it is.
     *
     * Returns null, without writing, when the unit is cancelled, a no-show or checked out,
     * or when the guest was merged into another guest or anonymized.
     */
    public function __invoke(UnitGuest $unitGuest): ?RegistrationCard
    {
        if (! $this->requirement->canHaveCard($unitGuest)) {
            return null;
        }

        $propertyId = $unitGuest->reservationUnit()->firstOrFail()->property_id;

        $open = fn (): ?RegistrationCard => RegistrationCard::query()
            ->where('unit_guest_id', $unitGuest->id)
            ->where('status', '<>', RegistrationCardStatus::Void->value)
            ->first();

        try {
            return $open() ?? DB::transaction(fn (): RegistrationCard => RegistrationCard::query()->forceCreate([
                'property_id' => $propertyId,
                'unit_guest_id' => $unitGuest->id,
                'status' => RegistrationCardStatus::Draft,
            ]));
        } catch (UniqueConstraintViolationException) {
            return $open();
        }
    }
}
