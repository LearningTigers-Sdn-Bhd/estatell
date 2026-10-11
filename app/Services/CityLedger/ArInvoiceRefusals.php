<?php

namespace App\Services\CityLedger;

use App\Actions\CityLedger\ArInvoiceResult;
use App\Services\Folio\InHouseUnits;

/**
 * Turns the refusal codes of the AR invoice Actions into a message and the form field it belongs to. A Dialog shows
 * the errors; an AlertDialog or a button shows `message()` as a toast. A refusal with no field uses `form`.
 */
class ArInvoiceRefusals
{
    public const string FORM = 'form';

    /**
     * @param  list<string>  $rooms  The rooms still in-house, for `units_in_house`.
     * @return array<string, string>
     */
    public function errors(string $code, array $rooms = []): array
    {
        [$field, $message] = $this->entry($code, $rooms);

        return [$field => $message];
    }

    /**
     * The errors for a refused credit note. `credit_exceeds_balance` names the balance (`MYR 200.00`) and, when
     * payments are still allocated, the receipts to reverse first. Other codes use `errors()`.
     *
     * @param  list<string>  $receipts  The receipt numbers of the active allocations.
     * @return array<string, string>
     */
    public function creditErrors(string $code, string $balanceLabel, array $receipts): array
    {
        if ($code !== ArInvoiceResult::REFUSAL_CREDIT_EXCEEDS_BALANCE) {
            return $this->errors($code);
        }

        $message = __('The credit cannot be more than the invoice balance (:balance).', ['balance' => $balanceLabel]);

        if ($receipts !== []) {
            $message .= ' '.trans_choice('{1} Reverse payment :receipts first.|[2,*] Reverse payments :receipts first.', count($receipts), ['receipts' => self::listed($receipts)]);
        }

        return ['amount' => $message];
    }

    /**
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
            ArInvoiceResult::REFUSAL_FOLIO_NOT_OPEN => [self::FORM, __('This folio is not open, so it cannot be settled.')],
            ArInvoiceResult::REFUSAL_UNITS_IN_HOUSE => [self::FORM, InHouseUnits::message($rooms)],
            ArInvoiceResult::REFUSAL_NO_COMPANY_PAYER => [self::FORM, __('This folio is not paid by a company.')],
            ArInvoiceResult::REFUSAL_DIRECT_BILL_NOT_ALLOWED => [self::FORM, __('This company is suspended or cannot be direct billed.')],
            ArInvoiceResult::REFUSAL_NOTHING_TO_SETTLE => [self::FORM, __('There is no balance to move to the city ledger.')],
            ArInvoiceResult::REFUSAL_NO_BUSINESS_DATE => [self::FORM, __('The property has no open business date.')],
            ArInvoiceResult::REFUSAL_NOT_AN_OPEN_INVOICE => [self::FORM, __('Only an invoice that is not void can take a credit note.')],
            ArInvoiceResult::REFUSAL_AMOUNT_INVALID => ['amount', __('Enter an amount greater than 0, such as 120.00.')],
            ArInvoiceResult::REFUSAL_REASON_REQUIRED => ['reason', __('Enter a reason for the credit note.')],
            ArInvoiceResult::REFUSAL_CREDIT_EXCEEDS_BALANCE => ['amount', __('The credit cannot be more than the invoice balance.')],
            ArInvoiceResult::REFUSAL_NOT_VOIDABLE => [self::FORM, __('Only an open invoice with no credit notes can be voided.')],
            ArInvoiceResult::REFUSAL_ALREADY_VOID => [self::FORM, __('This document is already void.')],
            default => [self::FORM, __('This could not be done.')],
        };
    }

    /**
     * @param  list<string>  $receipts
     */
    public static function listed(array $receipts): string
    {
        $last = array_pop($receipts);

        return $receipts === [] ? $last : implode(', ', $receipts).' '.__('and').' '.$last;
    }
}
