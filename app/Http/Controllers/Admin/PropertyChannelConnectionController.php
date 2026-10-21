<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Channels\ConnectChannel;
use App\Data\Channels\ChannelConnectPageData;
use App\Enums\ChannelProvider;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreChannelConnectionRequest;
use App\Http\Requests\Admin\TestChannelConnectionRequest;
use App\Models\OtaChannel;
use App\Models\Property;
use App\Services\Channels\ChannelActionResult;
use App\Services\Channels\ChannelConnectForm;
use App\Services\Channels\ChannelConnectionChecker;
use App\Services\Channels\ChannelDriverManager;
use App\Services\Channels\Channex\ChannexRequestFailed;
use App\Services\Channels\Channex\ChannexRetryLater;
use App\Services\Staff\PropertyOwners;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class PropertyChannelConnectionController extends Controller
{
    /**
     * Connect step 1. The form is drawn from the OTA adapter. A property that is not on Channex yet, or an OTA with no
     * adapter, goes back to the tab with the reason.
     */
    public function create(Property $property, OtaChannel $otaChannel, ChannelDriverManager $drivers, ChannelConnectForm $channelConnectForm, PropertyOwners $propertyOwners): Response|RedirectResponse
    {
        $channelProperty = $property->channelProperty;

        if ($channelProperty === null) {
            return $this->backToTab($property, ChannelActionResult::failed(__('Sync the structure to Channex first.')));
        }

        if ($otaChannel->channex_code === null || ! $otaChannel->is_active || $otaChannel->code === 'airbnb') {
            return $this->backToTab($property, ChannelActionResult::failed(__(':channel cannot be connected here. Connect it in Channex by hand.', ['channel' => $otaChannel->name])));
        }

        if ($channelProperty->connections()->where('ota_channel_id', $otaChannel->id)->exists()) {
            return $this->backToTab($property, ChannelActionResult::failed(__(':channel is already connected for this property.', ['channel' => $otaChannel->name])));
        }

        try {
            $adapter = $drivers->forProvider(ChannelProvider::Channex)->adapter($otaChannel->channex_code);
        } catch (ChannexRetryLater|ChannexRequestFailed) {
            return $this->backToTab($property, ChannelActionResult::failedForNow(__('Channex is busy or not reachable. Try again in a minute.')));
        }

        $credential = $property->otaCredentials()->with('channel')->where('ota_channel_id', $otaChannel->id)->first();

        return Inertia::render('admin/properties/channels/connect', [
            'property' => ['code' => $property->code, 'name' => $property->name],
            'connect' => ChannelConnectPageData::forProperty($property, $otaChannel, $channelConnectForm->fields($otaChannel->channex_code, $adapter), $credential, $propertyOwners->firstFor($property)),
        ]);
    }

    /**
     * Ask the OTA whether it knows the hotel ID, without saving anything.
     */
    public function test(TestChannelConnectionRequest $request, Property $property, OtaChannel $otaChannel, ChannelConnectionChecker $channelConnectionChecker): JsonResponse
    {
        $result = $channelConnectionChecker($otaChannel, $request->settings())->result;

        return response()->json(['ok' => $result->ok, 'message' => $result->message]);
    }

    /**
     * Test the settings again and create the connection as a draft.
     */
    public function store(StoreChannelConnectionRequest $request, Property $property, ConnectChannel $connectChannel): RedirectResponse
    {
        $channelProperty = $property->channelProperty;

        if ($channelProperty === null) {
            return $this->backToTab($property, ChannelActionResult::failed(__('Sync the structure to Channex first.')));
        }

        $result = $connectChannel($request->user(), $channelProperty, OtaChannel::query()->findOrFail($request->integer('ota_channel_id')), $request->settings());

        if (! $result->ok) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $result->message]);

            return back(fallback: route('admin.properties.channels', $property));
        }

        return $this->backToTab($property, $result);
    }

    private function backToTab(Property $property, ChannelActionResult $result): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $result->ok ? 'success' : 'error', 'message' => $result->message]);

        return redirect()->route('admin.properties.channels', $property);
    }
}
