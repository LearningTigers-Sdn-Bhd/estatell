<?php

namespace App\Http\Requests\Reservations;

/**
 * The walk-in form as the Payment section posts it for a preview: the same rules and booking data as a save. The
 * payment fields (`collect_room_charges`, `collect_deposit`, `payment_method_id`, `reference`, `expected_total`) are
 * not read here, because the preview answers every amount. The `can:reservations.manage` and `can:payment.record`
 * route middleware authorize it.
 */
class WalkInPaymentRequest extends StoreBookingRequest {}
