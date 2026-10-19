<?php

namespace App\Services\Folio;

use App\Actions\Folio\AddOldFolio;
use App\Actions\Folio\FolioStatusResult;
use App\Actions\Folio\ManualBatchResult;
use App\Actions\Folio\MoveChargesResult;
use App\Actions\Folio\PostingResult;
use App\Actions\Folio\SaveOldFoliosResult;
use App\Services\Catalogues\TaxCalculator;

/**
 * Turns the refusal codes of the folio Actions (posting, discounts, close and reopen) into a message and the form field it belongs to. A form shows the
 * errors in its Dialog; an AlertDialog shows `message()` as a toast. A refusal with no matching field uses `form`,
 * a message for the whole form. See docs/13-folio/05-folio-page.md.
 */
class FolioRefusals
{
    public const string FORM = 'form';

    /**
     * The errors to flash back to a form, keyed by field name.
     *
     * @param  list<string>  $rooms  The rooms still in-house for `units_in_house`, the rooms with nothing scheduled for `nothing_scheduled`, or the rooms and dates for `folio_receives_scheduled_charges`.
     * @return array<string, string>
     */
    public function errors(string $code, array $rooms = []): array
    {
        [$field, $message] = $this->entry($code, $rooms);

        return [$field => $message];
    }

    /**
     * The message alone, for a toast.
     *
     * @param  list<string>  $rooms
     */
    public function message(string $code, array $rooms = []): string
    {
        return $this->entry($code, $rooms)[1];
    }

    /**
     * @param  list<string>  $rooms
     * @return array{0: string, 1: string}
     */
    private function entry(string $code, array $rooms = []): array
    {
        return match ($code) {
            PostingResult::REFUSAL_NO_BUSINESS_DATE => [self::FORM, __('The property has no open business date, so nothing can be posted.')],
            PostingResult::REFUSAL_UNIT_NOT_POSTABLE => ['unit_id', __('Charges can be posted to units that are checked in or checked out.')],
            PostingResult::REFUSAL_UNIT_NOT_ON_FOLIO => ['unit_id', __('This room does not belong to the chosen folio. Pick its own folio or a Master folio.')],
            PostingResult::REFUSAL_CHARGE_NOT_FOUND => ['charge_item_id', __('This charge is not available.')],
            PostingResult::REFUSAL_QUANTITY_INVALID => ['quantity', __('Quantity must be from 1 to 999.')],
            PostingResult::REFUSAL_AMOUNT_INVALID => ['unit_amount', __('Enter an amount greater than 0.')],
            PostingResult::REFUSAL_AMOUNT_OVERRIDE_NOT_ALLOWED => ['unit_amount', __('The price of this charge cannot be changed.')],
            PostingResult::REFUSAL_FOLIO_NOT_OPEN => [self::FORM, __('This folio is closed. Reopen it on the folio tab first, then try again.')],
            PostingResult::REFUSAL_FOLIO_SETTLED => [self::FORM, __('The folio these charges go to is settled to the city ledger. Open a third-party folio for the company, add a routing rule for this room and this charge category to it, then post again.')],
            PostingResult::REFUSAL_LINE_IS_CHILD => [self::FORM, __('A tax or discount line moves and reverses with its charge.')],
            PostingResult::REFUSAL_ALREADY_REVERSED => [self::FORM, __('This line is already reversed.')],
            PostingResult::REFUSAL_LINE_IS_REVERSAL => [self::FORM, __('A reversal cannot be reversed.')],
            PostingResult::REFUSAL_TRANSFER_INVALID => ['target_folio_id', __('Choose a different folio of this reservation.')],
            PostingResult::REFUSAL_DIFFERENT_RESERVATION => ['target_folio_id', __('The folio must belong to the same reservation.')],
            PostingResult::REFUSAL_LINE_NOT_TRANSFERABLE => [self::FORM, __('A charge you ticked cannot be moved. It was already moved or reversed, or it belongs to another charge. Reload the page and try again.')],
            MoveChargesResult::REFUSAL_NOTHING_TO_MOVE => [self::FORM, __('Tick a charge or choose a scheduled option to move.')],
            MoveChargesResult::REFUSAL_TOO_MANY_CHARGES => [self::FORM, __('You can move up to 200 charges at once. Move them in two parts.')],
            MoveChargesResult::REFUSAL_NOTHING_SCHEDULED => [self::FORM, __('No scheduled nights are left to move for :rooms.', ['rooms' => implode(', ', $rooms)])],
            ManualBatchResult::REFUSAL_NOTHING_TO_POST => [self::FORM, __('Add at least one line.')],
            ManualBatchResult::REFUSAL_TOO_MANY_ROWS => [self::FORM, __('You can post up to :count lines at once. Post them in two parts.', ['count' => ManualBatchResult::MAX_ROWS])],
            ManualBatchResult::REFUSAL_UNIT_REQUIRED => ['unit_id', __('Pick the room this charge is for.')],
            PostingResult::REFUSAL_SERVICE_DATE_INVALID => ['service_date', __('Pick a day from the booking date to today.')],
            PostingResult::REFUSAL_TAX_NOT_AVAILABLE => ['tax_ids', __('A tax you chose is not available.')],
            PostingResult::REFUSAL_SPLIT_INVALID => [self::FORM, __('Split the line into 2 to 20 parts, each with a folio of this reservation and an amount above 0.')],
            PostingResult::REFUSAL_SPLIT_TOTAL_MISMATCH => [self::FORM, __('The parts must add up to the amount of the line.')],
            PostingResult::REFUSAL_LINE_HAS_DISCOUNT => [self::FORM, __('This charge has a discount. Remove the discount first.')],
            PostingResult::REFUSAL_TAX_NOT_ON_LINE => ['tax_ids', __('A tax you chose is not on this charge.')],
            PostingResult::REFUSAL_TAX_ALREADY_ON_LINE => ['tax_ids', __('A tax you chose is already on this charge.')],
            PostingResult::REFUSAL_TAX_INCLUSIVE => ['tax_ids', __('A tax that is part of the price cannot be added to a posted charge.')],
            PostingResult::REFUSAL_TAX_UNCHANGED => [self::FORM, __('The tax on this charge is already correct.')],
            PostingResult::REFUSAL_NET_AMOUNT_CHANGED => [self::FORM, __('The taxes of this item would change the price of the charge. Reverse the charge and post it again.')],
            PostingResult::REFUSAL_DISCOUNT_NOT_FOUND => ['discount_id', __('This discount is not available.')],
            PostingResult::REFUSAL_NOTHING_ELIGIBLE => ['discount_id', __('No charge on this folio can take this discount.')],
            PostingResult::REFUSAL_AMOUNT_REQUIRED => ['amount', __('Enter the discount amount.')],
            PostingResult::REFUSAL_QUOTE_CHANGED => [self::FORM, __('The folio changed. Check the new quote and apply again.')],
            PostingResult::REFUSAL_NOT_A_DISCOUNT => [self::FORM, __('This line is not a discount.')],
            PostingResult::REFUSAL_PAYMENT_LINE => [self::FORM, __('A payment cannot be reversed or moved. Refund it instead.')],
            FolioStatusResult::REFUSAL_UNITS_IN_HOUSE => [self::FORM, InHouseUnits::message($rooms)],
            FolioStatusResult::REFUSAL_RECEIVES_SCHEDULED_CHARGES => [self::FORM, IncomingScheduledCharges::message($rooms)],
            FolioStatusResult::REFUSAL_BALANCE_NOT_ZERO => [self::FORM, __('Only a folio with a balance of 0.00 can be closed. Settle it or move the charges first.')],
            FolioStatusResult::REFUSAL_HAS_AR_INVOICE => [self::FORM, __('This folio has a city ledger invoice. Issue a credit note instead of reopening it.')],
            FolioStatusResult::REFUSAL_NOT_CLOSED => [self::FORM, __('Only a closed folio can be reopened.')],
            TaxCalculator::REFUSAL_NATIONALITY_REQUIRED => [self::FORM, __('This charge has a tax that depends on nationality. Add the booker\'s nationality to the guest first.')],
            TaxCalculator::REFUSAL_INCLUSIVE_TAX_EXCEEDS_AMOUNT => ['unit_amount', __('The tax in this price is more than the amount.')],
            PostingResult::REFUSAL_MIGRATION_WINDOW_CLOSED => [self::FORM, __('The migration window is closed, so entries from the old system cannot be added or changed. Correct a line with a reversal or a refund.')],
            PostingResult::REFUSAL_REASON_REQUIRED => ['reason', __('Describe the reason.')],
            PostingResult::REFUSAL_OPENING_AMOUNT_INVALID => ['amount', __('Enter an amount such as 1,080.00. A minus amount is a credit.')],
            PostingResult::REFUSAL_NOT_OPENING_BALANCE => [self::FORM, __('This line is not an opening balance.')],
            PostingResult::REFUSAL_OPENING_BALANCE_EXISTS => [self::FORM, __('This folio already has an opening balance. Change it instead.')],
            SaveOldFoliosResult::REFUSAL_NOTHING_TO_SAVE => [self::FORM, __('Add at least one line.')],
            SaveOldFoliosResult::REFUSAL_TOO_MANY_ROWS => [self::FORM, __('You can save up to :count lines at once.', ['count' => SaveOldFoliosResult::MAX_ROWS])],
            SaveOldFoliosResult::REFUSAL_DATE_NOT_BEFORE_GO_LIVE => ['service_date', __('Pick a day before go-live. Lines from go-live on are posted by estatell.')],
            SaveOldFoliosResult::REFUSAL_UNIT_NOT_FOUND => ['item', __('This room night does not belong to the reservation.')],
            SaveOldFoliosResult::REFUSAL_FOLIO_NOT_FOUND => [self::FORM, __('This folio does not belong to the reservation.')],
            SaveOldFoliosResult::REFUSAL_ITEM_REQUIRED => ['item', __('Pick an item, a tax or a payment method.')],
            SaveOldFoliosResult::REFUSAL_DESCRIPTION_REQUIRED => ['description', __('Enter the old receipt number.')],
            AddOldFolio::REFUSAL_MASTER_EXISTS => [self::FORM, __('This reservation already has a Master folio.')],
            default => [self::FORM, __('This could not be done.')],
        };
    }
}
