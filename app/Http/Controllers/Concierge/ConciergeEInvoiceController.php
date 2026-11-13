<?php

namespace App\Http\Controllers\Concierge;

use App\Actions\Concierge\RequestConciergeEInvoice;
use App\Actions\EInvoice\EInvoiceResult;
use App\Data\Property\PropertySummaryData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Concierge\RequestEInvoiceRequest;
use App\Models\ReservationUnit;
use App\Services\Concierge\ConciergeEInvoice;
use App\Services\Guests\CountryOptions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The e-invoice request of a guest, from the stay's own link. Both routes accept a checked-out stay
 * (`concierge:checked-out`), because the folio closes at check-out.
 */
class ConciergeEInvoiceController extends Controller
{
    public function show(Request $request, string $token, ConciergeEInvoice $eInvoice, CountryOptions $countries): Response
    {
        /** @var ReservationUnit $stay */
        $stay = $request->attributes->get('concierge.stay');

        return Inertia::render('concierge/e-invoice', [
            'token' => $token,
            'property' => PropertySummaryData::fromModel($stay->property()->firstOrFail()),
            'eInvoice' => $eInvoice->for($stay),
            'countries' => $countries->optionsWithMalaysiaFirst(),
            'back' => ['href' => route('concierge.show', ['token' => $token], absolute: false), 'label' => __('Your stay')],
        ]);
    }

    public function store(RequestEInvoiceRequest $request, string $token, RequestConciergeEInvoice $requestEInvoice): RedirectResponse
    {
        /** @var ReservationUnit $stay */
        $stay = $request->attributes->get('concierge.stay');
        $result = $requestEInvoice($stay, $request->buyer());

        Inertia::flash('toast', $result->ok
            ? ['type' => 'success', 'message' => __('Your e-invoice request was sent.')]
            : ['type' => 'error', 'message' => $this->refusal($result)]);

        return to_route('concierge.e-invoice', ['token' => $token]);
    }

    /**
     * What the guest reads when a request is refused. Details only the property can fix (its tax number, its address)
     * become one plain line; what the guest can correct is shown as it is.
     */
    private function refusal(EInvoiceResult $result): string
    {
        return in_array($result->refusal, [
            EInvoiceResult::REFUSAL_BUYER_TIN_REQUIRED,
            EInvoiceResult::REFUSAL_BUYER_DETAILS_INCOMPLETE,
            EInvoiceResult::REFUSAL_PASSPORT_REQUIRED,
            EInvoiceResult::REFUSAL_ALREADY_EXISTS,
            RequestConciergeEInvoice::REFUSAL_NOT_REQUESTABLE,
        ], true)
            ? (string) $result->message
            : __('We could not send your e-invoice. Please ask the front desk.');
    }
}
