<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;

/**
 * The company's bookings at the property: list, detail, guest edits, cancel and cancellation request.
 *
 * Placeholder: the routes exist so both portal items can be built at the same time. `feat(portal): let company users book and manage stays` fills in the methods.
 */
class PortalBookingController extends Controller
{
    /**
     * The bookings list.
     */
    public function index(): never
    {
        abort(404);
    }

    /**
     * One booking.
     */
    public function show(): never
    {
        abort(404);
    }

    /**
     * Edit the booking's guests.
     */
    public function updateGuests(): never
    {
        abort(404);
    }

    /**
     * Cancel before the cut-off.
     */
    public function cancel(): never
    {
        abort(404);
    }

    /**
     * Ask the property to cancel after the cut-off.
     */
    public function requestCancellation(): never
    {
        abort(404);
    }
}
