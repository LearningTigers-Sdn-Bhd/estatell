<?php

namespace App\Services\Pos;

/**
 * The sentence staff read for a POS failure reason or an Action refusal. A code that is not listed is shown as it is,
 * so a new refusal is never hidden.
 */
class PosRefusalMessages
{
    public static function message(string $code): string
    {
        return match ($code) {
            'no_business_date' => __('The property has no open business date.'),
            'outlet_inactive' => __('The outlet or its charge item is not active.'),
            'room_not_matched' => __('No in-house guest was matched to this receipt.'),
            'unit_not_postable' => __('The guest is not checked in, so nothing can be posted.'),
            'folio_not_open' => __('The guest folio is closed. Reopen it or attach the receipt to another folio.'),
            'folio_settled_to_city_ledger' => __('The guest folio was settled to the city ledger.'),
            'folio_closed_cannot_void' => __('The folio is closed, so the charge could not be reversed. Adjust it by hand.'),
            'original_not_found' => __('The receipt being voided was never received.'),
            'original_not_posted' => __('The receipt being voided was not posted to a folio.'),
            'refund_amount_mismatch' => __('The refund is more than the original charge. Check the amount and adjust by hand.'),
            'partial_refund_manual_review' => __('A partial refund. Post the exact adjustment by hand.'),
            'service_date_invalid' => __('The service date is outside the stay.'),
            'already_reversed' => __('The charge was already reversed.'),
            'not_pending' => __('This posting was already settled.'),
            'note_required' => __('Write a note to ignore a posting.'),
            'unit_not_found' => __('Pick a guest to attach the receipt to.'),
            'attach_not_supported' => __('Only a charge can be attached to a folio.'),
            'action_invalid' => __('Choose to attach or to ignore.'),
            'code_invalid' => __('Use letters, numbers, dashes or underscores for the code.'),
            'code_taken' => __('Another outlet already uses this code.'),
            'charge_item_invalid' => __('Pick an active charge item of this property.'),
            'exclusive_tax_not_allowed' => __('This charge item has a tax that is not included in the price. POS receipts already include tax, so use a charge item whose taxes are inclusive.'),
            'merchant_in_use' => __('This Loyverse account is already connected to another property.'),
            'webhook_failed' => __('Loyverse connected, but the receipt webhook could not be registered. Connect again.'),
            'connect_failed' => __('Loyverse did not accept the connection. Try again.'),
            'outlet_invalid' => __('Pick an outlet of this property.'),
            'unit_not_eligible' => __('That guest is not in house for this meal.'),
            'meal_period_not_found' => __('That meal period does not exist.'),
            'guest_count_invalid' => __('Check in between 1 and 99 guests.'),
            'not_entitled' => __('This booking does not include that meal today.'),
            'exceeds_entitlement' => __('That is more guests than the booking includes for this meal.'),
            'already_undone' => __('That check-in was already undone.'),
            'service_date_closed' => __('That meal is on a closed business date and cannot be undone.'),
            'native_pos_disabled' => __('Native POS is not turned on for this property.'),
            'pin_invalid' => __('Use 4 to 6 digits for the PIN.'),
            'pin_too_short_for_role' => __('Your role needs a PIN of 6 digits.'),
            'open_pos_shifts_exist' => __('Close every open register shift before turning Native POS off.'),
            default => $code,
        };
    }
}
