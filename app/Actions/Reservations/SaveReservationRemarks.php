<?php

namespace App\Actions\Reservations;

use App\Enums\RemarkKind;
use App\Models\Reservation;
use App\Models\ReservationRemark;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SaveReservationRemarks
{
    /**
     * Replace the remarks of the booking with the given ones: a kind with text is added or changed, a kind left out is
     * removed. It works on closed reservations too, because it changes no money. Logs `reservation.remarks_updated`
     * with the kinds that changed, never the text (a dietary remark can be health information). A save that changes
     * nothing is not logged. See docs/12-reservations/16-remarks.md.
     *
     * @param  array<string, string>  $remarks  The trimmed text of each `RemarkKind` value that has one.
     */
    public function __invoke(User $actor, Reservation $reservation, array $remarks): void
    {
        DB::transaction(function () use ($actor, $reservation, $remarks): void {
            Reservation::query()->lockForUpdate()->findOrFail($reservation->id);

            $existing = $reservation->remarks()->get()->keyBy(fn (ReservationRemark $remark): string => $remark->kind->value);
            $changed = [];

            foreach (RemarkKind::cases() as $kind) {
                $old = $existing->get($kind->value);
                $new = $remarks[$kind->value] ?? null;

                if ($old?->body === $new) {
                    continue;
                }

                $changed[] = $kind->value;

                if ($new === null) {
                    $old?->delete();
                } elseif ($old !== null) {
                    $old->update(['body' => $new]);
                } else {
                    $remark = new ReservationRemark(['kind' => $kind, 'body' => $new]);
                    $remark->forceFill(['property_id' => $reservation->property_id, 'reservation_id' => $reservation->id])->save();
                }
            }

            if ($changed === []) {
                return;
            }

            activity()
                ->performedOn($reservation)
                ->causedBy($actor)
                ->event('reservation.remarks_updated')
                ->withProperties(['property_id' => $reservation->property_id, 'kinds' => $changed])
                ->log('Reservation remarks updated');
        });

        $reservation->unsetRelation('remarks');
    }
}
