<?php

namespace App\Http\Controllers\Reservations;

use App\Actions\Folio\BillToGuest;
use App\Actions\Folio\CreateFolio;
use App\Actions\Folio\CreateRoutingRule;
use App\Actions\Folio\RemoveRoutingRule;
use App\Http\Controllers\Controller;
use App\Http\Requests\Folio\StoreRoutingRuleRequest;
use App\Http\Requests\Folio\StoreThirdPartyFolioRequest;
use App\Models\Company;
use App\Models\FolioRoutingRule;
use App\Models\Guest;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Services\Folio\RoutingRuleRefusals;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Third-party folios and Manual routing rules of one reservation, from the folio tab. See
 * docs/13-folio/03-routing.md#staff-screens.
 */
class ReservationRoutingController extends Controller
{
    /**
     * Create a third-party folio paid by a guest or by an Active company of the property, then open it on the folio
     * tab. A merged or anonymized guest, or a guest of another property, is a 404. A company that is Suspended or of
     * another property is a validation error on `payer_company_id`.
     */
    public function storeThirdPartyFolio(StoreThirdPartyFolioRequest $request, Property $property, Reservation $reservation, CreateFolio $createFolio): RedirectResponse
    {
        $guest = $request->filled('payer_guest_id')
            ? Guest::query()
                ->where('property_id', $property->id)
                ->notMerged()
                ->whereNull('anonymized_at')
                ->findOrFail($request->integer('payer_guest_id'))
            : null;
        $company = $guest === null ? Company::query()->where('property_id', $property->id)->findOrFail($request->integer('payer_company_id')) : null;

        $folio = $createFolio->thirdParty($reservation, $guest, $company?->id, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Folio :number created for :payer.', ['number' => $folio->number, 'payer' => $guest?->full_name ?? $company?->name])]);

        return to_route('reservations.folio', ['property' => $property, 'reservation' => $reservation, 'folio' => $folio->id]);
    }

    /**
     * Bill a guest of a unit on a folio of their own: the primary guest pays the unit folio, any other guest gets a
     * third-party folio. It has no form, so a refusal is an error toast. A guest or unit outside the reservation is a 404.
     */
    public function billToGuest(Request $request, Property $property, Reservation $reservation, ReservationUnit $unit, BillToGuest $billToGuest, RoutingRuleRefusals $refusals): RedirectResponse
    {
        abort_unless($unit->reservation_id === $reservation->id, 404);

        $guest = Guest::query()
            ->where('property_id', $property->id)
            ->notMerged()
            ->whereNull('anonymized_at')
            ->findOrFail($request->integer('guest_id'));
        $result = $billToGuest($request->user(), $unit, $guest);

        Inertia::flash('toast', $result->isRefused()
            ? ['type' => 'error', 'message' => $refusals->message((string) $result->refusal)]
            : ['type' => 'success', 'message' => __(':name is billed on folio :number.', ['name' => $guest->full_name, 'number' => $result->folio->number])]);

        return back(fallback: $this->page($property, $reservation));
    }

    /**
     * Add a Manual routing rule. A target folio outside the reservation is a 404. A refusal goes back to the form as
     * an error.
     */
    public function storeRule(StoreRoutingRuleRequest $request, Property $property, Reservation $reservation, CreateRoutingRule $createRoutingRule, RoutingRuleRefusals $refusals): RedirectResponse
    {
        $target = $reservation->folios()->findOrFail($request->integer('target_folio_id'));
        $result = $createRoutingRule->manual($request->user(), $reservation, $target, $request->reservationUnitId(), $request->category(), $request->transactionCodeId());

        if ($result->isRefused()) {
            return back(fallback: $this->page($property, $reservation))->withErrors($refusals->errors((string) $result->refusal));
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Routing rule added. Later charges go to folio :number.', ['number' => $target->number])]);

        return back(fallback: $this->page($property, $reservation));
    }

    /**
     * Remove a Manual routing rule. It has no form, so a refusal is an error toast.
     */
    public function destroyRule(Request $request, Property $property, Reservation $reservation, FolioRoutingRule $routingRule, RemoveRoutingRule $removeRoutingRule, RoutingRuleRefusals $refusals): RedirectResponse
    {
        $result = $removeRoutingRule($request->user(), $routingRule);

        Inertia::flash('toast', $result->isRefused()
            ? ['type' => 'error', 'message' => $refusals->message((string) $result->refusal)]
            : ['type' => 'success', 'message' => __('Routing rule removed.')]);

        return back(fallback: $this->page($property, $reservation));
    }

    private function page(Property $property, Reservation $reservation): string
    {
        return route('reservations.folio', ['property' => $property, 'reservation' => $reservation]);
    }
}
