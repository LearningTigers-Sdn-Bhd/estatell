<?php

namespace App\Http\Controllers\Pos;

use App\Actions\Pos\RegisterPosTerminalDevice;
use App\Actions\Pos\RevokePosTerminalDevice;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\RegisterPosTerminalDeviceRequest;
use App\Models\PosOutlet;
use App\Models\PosTerminalDevice;
use App\Models\Property;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PosTerminalDeviceController extends Controller
{
    public function store(RegisterPosTerminalDeviceRequest $request, Property $property, RegisterPosTerminalDevice $registerPosTerminalDevice): RedirectResponse
    {
        $outlet = PosOutlet::query()->where('property_id', $property->id)->find((int) $request->validated('pos_outlet_id'));

        $registered = $outlet === null ? null : $registerPosTerminalDevice($request->user(), $property, $outlet, (string) $request->validated('name'));

        if ($registered === null) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Pick an outlet of this property.')]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('This browser is now a POS terminal.')]);

        return back()->withCookie(cookie(
            name: PosTerminalDevice::COOKIE,
            value: $registered['token'],
            minutes: 60 * 24 * 365 * 5,
            secure: $request->secure(),
            httpOnly: true,
            sameSite: 'lax',
        ));
    }

    public function revoke(Request $request, Property $property, int $device, RevokePosTerminalDevice $revokePosTerminalDevice): RedirectResponse
    {
        $model = PosTerminalDevice::query()->where('property_id', $property->id)->findOrFail($device);

        Inertia::flash('toast', $revokePosTerminalDevice($request->user(), $property, $model)
            ? ['type' => 'success', 'message' => __('Terminal revoked.')]
            : ['type' => 'error', 'message' => __('That terminal is not active.')]);

        return back();
    }
}
