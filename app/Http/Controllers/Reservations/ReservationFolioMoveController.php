<?php

namespace App\Http\Controllers\Reservations;

use App\Actions\Folio\MoveCharges;
use App\Data\Folio\MovePreviewData;
use App\Enums\FolioStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Folio\MoveChargesRequest;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Services\Folio\FolioRefusals;
use App\Services\Folio\MoveChargesPreview;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Inertia\Inertia;

/**
 * The Move charges page of a reservation: the preview of a choice and the save. A folio, line or unit of another
 * reservation is a 404. See docs/13-folio/07-move-charges.md.
 */
class ReservationFolioMoveController extends Controller
{
    /**
     * The figures and notes for a choice. It writes nothing. A target that is not Open is a 422.
     */
    public function preview(MoveChargesRequest $request, Property $property, Reservation $reservation, MoveChargesPreview $preview): JsonResponse
    {
        $target = $reservation->folios()->findOrFail($request->integer('target_folio_id'));
        $this->lines($request, $reservation);
        $units = $this->units($request, $reservation);

        abort_unless($target->status === FolioStatus::Open, 422);

        return response()->json($this->previewData($preview, $property, $reservation, $target, $request, $units));
    }

    /**
     * Move the ticked charges to the target and save the scheduled choices. A refusal goes back to the page as an error
     * and writes nothing. On success it opens the folio tab on the target with a toast.
     */
    public function store(MoveChargesRequest $request, Property $property, Reservation $reservation, MoveCharges $moveCharges, MoveChargesPreview $preview, FolioRefusals $refusals): RedirectResponse
    {
        $target = $reservation->folios()->findOrFail($request->integer('target_folio_id'));
        $lines = $this->lines($request, $reservation);
        $units = $this->units($request, $reservation);
        $result = $moveCharges($request->user(), $target, $lines->all(), $units->map(fn (ReservationUnit $unit): array => ['unit' => $unit, 'scope' => $request->scopes()[$unit->id]])->values()->all());

        if ($result->isRefused()) {
            return back(fallback: $this->folioTab($property, $reservation, $target))->withErrors($refusals->errors((string) $result->refusal, $result->rooms));
        }

        $to = $preview->folioLabel($target);
        $moved = $result->chargeCount === 1 ? __('1 charge moved to :to.', ['to' => $to]) : __(':count charges moved to :to.', ['count' => $result->chargeCount, 'to' => $to]);
        $scheduled = $result->scheduleCount === 1 ? __('Scheduled nights of 1 room will be charged there too.') : __('Scheduled nights of :count rooms will be charged there too.', ['count' => $result->scheduleCount]);
        $message = match (true) {
            $result->chargeCount === 0 => $result->scheduleCount === 1
                ? __('Scheduled nights of 1 room will be charged to :to.', ['to' => $to])
                : __('Scheduled nights of :count rooms will be charged to :to.', ['count' => $result->scheduleCount, 'to' => $to]),
            $result->scheduleCount > 0 => $moved.' '.$scheduled,
            default => $moved,
        };

        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return redirect($this->folioTab($property, $reservation, $target));
    }

    /**
     * @param  Collection<int, ReservationUnit>  $units
     */
    private function previewData(MoveChargesPreview $preview, Property $property, Reservation $reservation, Folio $target, MoveChargesRequest $request, Collection $units): MovePreviewData
    {
        return $preview->preview(
            $property,
            $reservation,
            $target,
            $request->lineIds(),
            $units->map(fn (ReservationUnit $unit): array => ['unit' => $unit, 'scope' => $request->scopes()[$unit->id]])->values()->all(),
        );
    }

    /**
     * The ticked lines. One that is not on a folio of this reservation is a 404.
     *
     * @return Collection<int, FolioLine>
     */
    private function lines(MoveChargesRequest $request, Reservation $reservation): Collection
    {
        $ids = array_values(array_unique($request->lineIds()));
        $lines = FolioLine::query()->whereIn('folio_id', $reservation->folios()->select('folios.id'))->whereIn('id', $ids)->get();

        abort_unless($lines->count() === count($ids), 404);

        return $lines;
    }

    /**
     * The rooms with a scheduled choice. One that is not a unit of this reservation is a 404.
     *
     * @return Collection<int, ReservationUnit>
     */
    private function units(MoveChargesRequest $request, Reservation $reservation): Collection
    {
        $ids = array_keys($request->scopes());
        $units = $reservation->units()->whereIn('id', $ids)->get();

        abort_unless($units->count() === count($ids), 404);

        return $units;
    }

    private function folioTab(Property $property, Reservation $reservation, Folio $target): string
    {
        return route('reservations.folio', ['property' => $property, 'reservation' => $reservation, 'folio' => $target->id]);
    }
}
