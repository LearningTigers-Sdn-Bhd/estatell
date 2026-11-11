<?php

namespace App\Http\Controllers\Concierge;

use App\Actions\Concierge\AddFaq;
use App\Actions\Concierge\AddWifiNetwork;
use App\Actions\Concierge\RemoveFaq;
use App\Actions\Concierge\RemoveWifiNetwork;
use App\Actions\Concierge\UpdateConciergeSettings;
use App\Data\Concierge\ConciergeFaqData;
use App\Data\Concierge\ConciergeWifiData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Concierge\StoreFaqRequest;
use App\Http\Requests\Concierge\StoreWifiRequest;
use App\Http\Requests\Concierge\UpdateSettingsRequest;
use App\Models\Property;
use App\Models\PropertyFaq;
use App\Models\PropertyWifiNetwork;
use App\Services\Concierge\ConciergeLobbyQr;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The staff side of the Concierge page: the lobby QR, the switches and the content guests read (house rules, Wi-Fi
 * networks and FAQs).
 */
class ConciergeSettingsController extends Controller
{
    /**
     * The staff page with the lobby address and its QR code to print.
     */
    public function index(Property $property, ConciergeLobbyQr $qr): Response
    {
        $url = route('concierge.lobby', ['property' => $property->code]);

        $property->loadMissing('settings');

        return Inertia::render('settings/concierge/index', [
            'lobbyUrl' => $url,
            'lobbyQr' => $qr->dataUri($url),
            'walkInEnabled' => (bool) $property->settings?->concierge_walk_in_enabled,
            'refundRequestsEnabled' => (bool) $property->settings?->concierge_refund_requests_enabled,
            'houseRules' => $property->settings?->house_rules,
            'wifiNetworks' => $property->wifiNetworks()->get()->map(ConciergeWifiData::fromModel(...))->values(),
            'faqs' => $property->faqs()->get()->map(ConciergeFaqData::fromModel(...))->values(),
        ]);
    }

    public function update(UpdateSettingsRequest $request, Property $property, UpdateConciergeSettings $updateSettings): RedirectResponse
    {
        $updateSettings($request->user(), $property, $request->boolean('walk_in_enabled'), $request->boolean('refund_requests_enabled'), $request->input('house_rules'));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Concierge settings saved.')]);

        return to_route('settings.concierge.index', $property);
    }

    public function storeWifi(StoreWifiRequest $request, Property $property, AddWifiNetwork $addNetwork): RedirectResponse
    {
        $network = $addNetwork($request->user(), $property, $request->string('name')->toString(), $request->input('password'), $request->input('note'));

        Inertia::flash('toast', $network === null
            ? ['type' => 'error', 'message' => __('A property can list up to :count Wi-Fi networks.', ['count' => AddWifiNetwork::MAX])]
            : ['type' => 'success', 'message' => __('Wi-Fi network added.')]);

        return to_route('settings.concierge.index', $property);
    }

    public function destroyWifi(Request $request, Property $property, PropertyWifiNetwork $wifiNetwork, RemoveWifiNetwork $removeNetwork): RedirectResponse
    {
        $removeNetwork($request->user(), $wifiNetwork);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Wi-Fi network removed.')]);

        return to_route('settings.concierge.index', $property);
    }

    public function storeFaq(StoreFaqRequest $request, Property $property, AddFaq $addFaq): RedirectResponse
    {
        $faq = $addFaq($request->user(), $property, $request->string('question')->toString(), $request->string('answer')->toString());

        Inertia::flash('toast', $faq === null
            ? ['type' => 'error', 'message' => __('A property can list up to :count questions.', ['count' => AddFaq::MAX])]
            : ['type' => 'success', 'message' => __('Question added.')]);

        return to_route('settings.concierge.index', $property);
    }

    public function destroyFaq(Request $request, Property $property, PropertyFaq $faq, RemoveFaq $removeFaq): RedirectResponse
    {
        $removeFaq($request->user(), $faq);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Question removed.')]);

        return to_route('settings.concierge.index', $property);
    }
}
