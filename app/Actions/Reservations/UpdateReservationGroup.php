<?php

namespace App\Actions\Reservations;

use App\Actions\Folio\SyncRoutingRules;
use App\Enums\BillingMode;
use App\Models\Reservation;
use App\Models\User;
use App\Services\Folio\MasterPaymentsHeld;
use Illuminate\Support\Facades\DB;

class UpdateReservationGroup
{
    public function __construct(private SyncRoutingRules $syncRoutingRules, private MasterPaymentsHeld $masterPaymentsHeld) {}

    /**
     * Change the group name and billing mode. A blank name clears it. A billing mode change is refused while the Master folio holds money (`master_has_payments`); the refusal writes and logs nothing. Nothing is written or logged when nothing changed. A changed billing mode runs `SyncRoutingRules`: later postings follow the new rules, and the result names units whose posted room lines stay on their own folio.
     */
    public function __invoke(User $actor, Reservation $reservation, ?string $groupName, BillingMode $billingMode): GroupUpdateResult
    {
        return DB::transaction(function () use ($actor, $reservation, $groupName, $billingMode): GroupUpdateResult {
            $locked = Reservation::query()->lockForUpdate()->findOrFail($reservation->id);
            $groupName = $groupName === null || trim($groupName) === '' ? null : trim($groupName);

            $unitsWithPostedRoomLines = [];
            $unitAdvances = [];

            if ($locked->billing_mode !== $billingMode) {
                $held = $this->masterPaymentsHeld->forReservation($locked);

                if (bccomp($held, '0', 2) > 0) {
                    return GroupUpdateResult::refused($reservation, GroupUpdateResult::REFUSAL_MASTER_HAS_PAYMENTS, $held);
                }
            }

            if ($locked->group_name !== $groupName || $locked->billing_mode !== $billingMode) {
                $modeChanged = $locked->billing_mode !== $billingMode;
                $old = ['group_name' => $locked->group_name, 'billing_mode' => $locked->billing_mode->value];

                $locked->group_name = $groupName;
                $locked->billing_mode = $billingMode;
                $locked->save();

                if ($modeChanged) {
                    $routing = ($this->syncRoutingRules)($locked);
                    $unitsWithPostedRoomLines = $routing->unitsWithPostedRoomLines;
                    $unitAdvances = $routing->unitAdvances;
                }

                activity()
                    ->performedOn($locked)
                    ->causedBy($actor)
                    ->event('reservation.group_updated')
                    ->withProperties([
                        'property_id' => $locked->property_id,
                        'old' => $old,
                        'new' => ['group_name' => $groupName, 'billing_mode' => $billingMode->value],
                    ])
                    ->log('Reservation group updated');
            }

            $reservation->setRawAttributes($locked->getAttributes(), true);

            return new GroupUpdateResult($reservation, $unitsWithPostedRoomLines, unitAdvances: $unitAdvances);
        });
    }
}
