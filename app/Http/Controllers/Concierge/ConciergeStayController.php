<?php

namespace App\Http\Controllers\Concierge;

use App\Actions\Concierge\CheckOutConciergeStay;
use App\Actions\Concierge\RequestConciergeCheckOut;
use App\Actions\Concierge\RequestConciergeRefund;
use App\Actions\Concierge\SubmitConciergeRequest;
use App\Actions\FrontDesk\UnitStayResult;
use App\Data\Property\PropertySummaryData;
use App\Enums\GuestRequestType;
use App\Enums\UnitStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Concierge\AskCheckOutRequest;
use App\Http\Requests\Concierge\RefundRequestRequest;
use App\Http\Requests\Concierge\SubmitRequestRequest;
use App\Models\ReservationUnit;
use App\Services\Concierge\ConciergeBill;
use App\Services\Concierge\ConciergeContent;
use App\Services\Concierge\ConciergeDocuments;
use App\Services\Concierge\ConciergeRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The pages of a guest who is checked in: requests, amenities, nearby places and the bill with check-out. They work from
 * the stay's own link like the check-in pages (`EnsureConciergeIsActive`) and only for a stay that is CheckedIn: any other
 * stay goes back to the stay page, which shows the step it is on.
 */
class ConciergeStayController extends Controller
{
    public function requests(Request $request, string $token, ConciergeRequests $requests): Response|RedirectResponse
    {
        $stay = $this->inHouse($request, $token);

        if ($stay instanceof RedirectResponse) {
            return $stay;
        }

        return Inertia::render('concierge/requests', [
            ...$this->frame($stay, $token),
            'requests' => $requests->forStay($stay)->values(),
            'selectedType' => $this->selectedType($request),
            'types' => array_values(array_filter(GuestRequestType::options(), fn (array $type): bool => $type['value'] !== GuestRequestType::Refund->value)),
        ]);
    }

    public function submitRequest(SubmitRequestRequest $request, string $token, SubmitConciergeRequest $submitRequest): RedirectResponse
    {
        $stay = $this->inHouse($request, $token);

        if ($stay instanceof RedirectResponse) {
            return $stay;
        }

        $result = $submitRequest($stay, GuestRequestType::from($request->string('type')->toString()), $request->string('details')->toString());

        if ($result->isRefused()) {
            return back()->withErrors(['details' => $result->refusal->label()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Your request was sent to the front desk.')]);

        return to_route('concierge.requests', ['token' => $token]);
    }

    public function amenities(Request $request, string $token, ConciergeContent $content): Response|RedirectResponse
    {
        $stay = $this->inHouse($request, $token);

        if ($stay instanceof RedirectResponse) {
            return $stay;
        }

        return Inertia::render('concierge/amenities', [...$this->frame($stay, $token), 'amenities' => $content->amenities($stay->property)->values()]);
    }

    public function nearby(Request $request, string $token, ConciergeContent $content): Response|RedirectResponse
    {
        $stay = $this->inHouse($request, $token);

        if ($stay instanceof RedirectResponse) {
            return $stay;
        }

        return Inertia::render('concierge/nearby', [...$this->frame($stay, $token), 'places' => $content->places($stay->property)->values()]);
    }

    public function bill(Request $request, string $token, ConciergeBill $bill, ConciergeDocuments $documents): Response|RedirectResponse
    {
        $stay = $this->inHouse($request, $token);

        if ($stay instanceof RedirectResponse) {
            return $stay;
        }

        return Inertia::render('concierge/bill', [...$this->frame($stay, $token), 'bill' => $bill->data($stay), 'documents' => $documents->links($stay, $token)]);
    }

    public function checkOut(Request $request, string $token, CheckOutConciergeStay $checkOut): RedirectResponse
    {
        $stay = $this->inHouse($request, $token);

        if ($stay instanceof RedirectResponse) {
            return $stay;
        }

        $result = $checkOut($stay);

        if ($result->isRefused()) {
            Inertia::flash('toast', ['type' => 'error', 'message' => match ($result->refusal) {
                UnitStayResult::REFUSAL_BALANCE_NOT_SETTLED => __('Your bill is not settled yet. Pay it on the bill page, or ask the front desk.'),
                UnitStayResult::REFUSAL_BEFORE_DEPARTURE_DATE => __('Check-out opens on your departure day. Ask the front desk for an early check-out.'),
                default => __('We could not check you out here. Please ask the front desk.'),
            }]);

            return to_route('concierge.bill', ['token' => $token]);
        }

        return to_route('concierge.show', ['token' => $token]);
    }

    /**
     * A guest who cannot check out alone asks the front desk to do it, with an optional note.
     */
    public function askCheckOut(AskCheckOutRequest $request, string $token, RequestConciergeCheckOut $askCheckOut): RedirectResponse
    {
        $stay = $this->inHouse($request, $token);

        if ($stay instanceof RedirectResponse) {
            return $stay;
        }

        $result = $askCheckOut($stay, $request->input('note'));

        Inertia::flash('toast', $result->isRefused()
            ? ['type' => 'error', 'message' => __('The front desk already has your check-out request.')]
            : ['type' => 'success', 'message' => __('The front desk has your check-out request.')]);

        return to_route('concierge.bill', ['token' => $token]);
    }

    /**
     * A guest who has checked out asks for a refund. It becomes a request for staff to answer. This route also accepts a
     * checked-out stay (`concierge:checked-out`), which no other write does.
     */
    public function requestRefund(RefundRequestRequest $request, string $token, RequestConciergeRefund $requestRefund): RedirectResponse
    {
        /** @var ReservationUnit $stay */
        $stay = $request->attributes->get('concierge.stay');
        $result = $requestRefund($stay, $request->string('details')->toString());

        Inertia::flash('toast', $result->isRefused()
            ? ['type' => 'error', 'message' => __('A refund request cannot be sent for this stay, or one is already open. Ask the front desk.')]
            : ['type' => 'success', 'message' => __('Your refund request was sent. The property will reply to you.')]);

        return to_route('concierge.show', ['token' => $token]);
    }

    /**
     * The request type a tile of the stay screen picked in the link (`?type=housekeeping`), or housekeeping. A refund is
     * never picked here: it has its own form after check-out.
     */
    private function selectedType(Request $request): string
    {
        $type = GuestRequestType::tryFrom($request->string('type')->toString());

        return $type === null || $type === GuestRequestType::Refund ? GuestRequestType::Housekeeping->value : $type->value;
    }

    /**
     * The stay, when it is checked in; otherwise a redirect to the stay page.
     */
    private function inHouse(Request $request, string $token): ReservationUnit|RedirectResponse
    {
        /** @var ReservationUnit $stay */
        $stay = $request->attributes->get('concierge.stay');

        return $stay->status === UnitStatus::CheckedIn ? $stay : to_route('concierge.show', ['token' => $token]);
    }

    /**
     * @return array{token: string, property: PropertySummaryData, back: array{href: string, label: string}}
     */
    private function frame(ReservationUnit $stay, string $token): array
    {
        $stay->loadMissing('property');

        return [
            'token' => $token,
            'property' => PropertySummaryData::fromModel($stay->property),
            'back' => ['href' => route('concierge.show', ['token' => $token], absolute: false), 'label' => __('Your stay')],
        ];
    }
}
