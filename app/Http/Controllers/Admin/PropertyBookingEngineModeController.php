<?php

namespace App\Http\Controllers\Admin;

use App\Actions\BookingEngine\SetBookingEngineMode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateBookingEngineModeRequest;
use App\Models\Property;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class PropertyBookingEngineModeController extends Controller
{
    /**
     * Set how the property takes online bookings: Microsite or API only.
     */
    public function update(UpdateBookingEngineModeRequest $request, Property $property, SetBookingEngineMode $setBookingEngineMode): RedirectResponse
    {
        $mode = $request->mode();

        Inertia::flash('toast', $setBookingEngineMode($request->user(), $property, $mode)
            ? ['type' => 'success', 'message' => __('Booking engine mode set to :mode.', ['mode' => $mode->label()])]
            : ['type' => 'error', 'message' => __('Turn on the booking engine first, or pick another mode.')]);

        return back(fallback: route('admin.properties.features', $property));
    }
}
