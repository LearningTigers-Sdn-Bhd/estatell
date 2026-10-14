<?php

namespace App\Http\Controllers\Reservations;

use App\Actions\FrontDesk\CheckOutGroup;
use App\Data\Property\CurrencyData;
use App\Data\Reservations\GroupCheckOutDoneData;
use App\Data\Reservations\GroupCheckOutPageData;
use App\Enums\PermissionEnum;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reservations\CheckOutGroupPreviewRequest;
use App\Http\Requests\Reservations\CheckOutGroupReceiptsRequest;
use App\Http\Requests\Reservations\CheckOutGroupRequest;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Reservation;
use App\Services\FrontDesk\GroupCheckOutPreview;
use App\Services\FrontDesk\GroupCheckOutRefusals;
use App\Services\Payments\PaymentReceipt;
use App\Services\Property\PrintedPdf;
use App\Services\Reservations\ReservationFormOptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Group check-out: the read-only preview of the rooms and choices, and the save through `CheckOutGroup`. A unit of
 * another reservation is a 404. See docs/18-front-desk/05-group-check-out.md.
 */
class ReservationGroupCheckOutController extends Controller
{
    /**
     * The page: the rooms, the Master line and the summary of the prefilled choices. `canPrintReceipts` shows Print receipts on the
     * success state. `paymentOptions` and `canWaiveFees` are for the **Check out** Dialog that **Check out early…** opens, as on the reservation page.
     * With no room left to check out the page redirects to the reservation with a toast, unless it is the success state of a save.
     */
    public function show(Request $request, Property $property, Reservation $reservation, GroupCheckOutPreview $preview, ReservationFormOptions $options): Response|RedirectResponse
    {
        $rooms = $preview->forReservation($reservation, $request->user());
        $justSaved = array_key_exists('groupCheckOut', Inertia::getFlashed($request));

        if ($rooms->rows === [] && $rooms->error === null && ! $justSaved) {
            Inertia::flash('toast', ['type' => 'info', 'message' => __('No rooms are left to check out.')]);

            return redirect()->route('reservations.show', ['property' => $property, 'reservation' => $reservation]);
        }

        $reservation->loadMissing(['booker', 'company']);
        $payer = $reservation->company?->name ?? $reservation->group_name ?? $reservation->booker->full_name;

        return Inertia::render('reservations/check-out', [
            'checkOut' => new GroupCheckOutPageData(
                $reservation->id,
                $reservation->reference,
                __('Check out · :reference · :name', ['reference' => $reservation->reference, 'name' => $payer]),
                CurrencyData::forProperty($property),
                $rooms,
                route('reservations.show', ['property' => $property, 'reservation' => $reservation]),
            ),
            'canWaiveFees' => $request->user()->can(PermissionEnum::FrontDeskWaiveFees->value),
            'canPrintReceipts' => $request->user()->can(PermissionEnum::PaymentRecord->value),
            'paymentOptions' => Inertia::optional(fn () => $options->payments($property)),
        ]);
    }

    /**
     * The rooms, the Master line and the summary. It writes nothing.
     */
    public function preview(CheckOutGroupPreviewRequest $request, Property $property, Reservation $reservation, GroupCheckOutPreview $preview): JsonResponse
    {
        $this->units($request, $reservation);

        return response()->json($preview->forReservation(
            $reservation,
            $request->user(),
            $request->hasRows() ? $request->groupRows() : null,
            $request->onePayment(),
            $request->settleMaster(),
        ));
    }

    /**
     * Check out the chosen rooms and settle their money in one save. A refusal saves nothing and goes back as an error
     * on its room (`units.{id}`) or on the form.
     */
    public function store(CheckOutGroupRequest $request, Property $property, Reservation $reservation, CheckOutGroup $checkOutGroup, GroupCheckOutRefusals $refusals, GroupCheckOutPreview $preview): RedirectResponse
    {
        $this->units($request, $reservation);
        $result = $checkOutGroup($request->user(), $reservation, $request->groupRows(), $request->onePayment(), $request->settleMaster());
        $page = route('reservations.check-out', ['property' => $property, 'reservation' => $reservation]);

        if ($result->isRefused()) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Nothing was saved. Check the rooms marked with an error.')]);

            return back(fallback: $page)->withErrors($refusals->errors($result));
        }

        $master = $preview->masterLine($reservation, $request->user());
        $masterUrl = $master === null ? null : route('reservations.folio', ['property' => $property, 'reservation' => $reservation, 'folio' => $master->folioId]);

        Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice(':count room checked out.|:count rooms checked out.', count($result->checkedOutUnitIds))]);
        Inertia::flash('groupCheckOut', GroupCheckOutDoneData::fromResult($result, $master, $masterUrl)->toArray());

        return redirect($page);
    }

    /**
     * One PDF with the receipt of every payment the save recorded, in id order, one per page. Every id must be a
     * payment of this reservation and property and not a refund (a refund has no receipt), else 404.
     */
    public function receipts(CheckOutGroupReceiptsRequest $request, Property $property, Reservation $reservation, PaymentReceipt $paymentReceipt, PrintedPdf $printedPdf): HttpResponse
    {
        $ids = $request->paymentIds();
        $payments = Payment::query()
            ->whereIn('id', $ids)
            ->whereNull('refund_of_id')
            ->whereHas('folio', fn ($folios) => $folios->where('reservation_id', $reservation->id)->where('property_id', $property->id))
            ->orderBy('id')
            ->get();

        abort_unless($payments->count() === count($ids), 404);

        $receipts = $payments->map(fn (Payment $payment) => $paymentReceipt->forPayment($payment))->all();

        return $printedPdf->make($property, 'pdf.receipts', ['receipts' => $receipts])
            ->stream('receipts-'.$reservation->reference.'.pdf');
    }

    private function units(CheckOutGroupRequest $request, Reservation $reservation): void
    {
        $ids = array_values(array_unique($request->unitIds()));

        abort_unless($reservation->units()->whereIn('id', $ids)->count() === count($ids), 404);
    }
}
