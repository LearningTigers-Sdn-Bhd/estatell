<?php

namespace App\Services\CityLedger;

use App\Actions\CityLedger\ArPaymentResult;

/**
 * Turns the refusal codes of the AR payment Actions into a message and the form field it belongs to. A Dialog shows
 * the errors; an AlertDialog shows `message()` as a toast. A refusal with no field uses `form`.
 */
class ArPaymentRefusals
{
    public const string FORM = 'form';

    /**
     * @return array<string, string>
     */
    public function errors(string $code): array
    {
        [$field, $message] = $this->entry($code);

        return [$field => $message];
    }

    public function message(string $code): string
    {
        return $this->entry($code)[1];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function entry(string $code): array
    {
        return match ($code) {
            ArPaymentResult::REFUSAL_METHOD_NOT_AVAILABLE => ['payment_method_id', __('This payment method is not available.')],
            ArPaymentResult::REFUSAL_AMOUNT_INVALID => ['amount', __('Enter an amount greater than 0, such as 1,200.00.')],
            ArPaymentResult::REFUSAL_REFERENCE_REQUIRED => ['reference', __('This method needs a reference.')],
            ArPaymentResult::REFUSAL_DATE_IN_FUTURE => ['received_on', __('The payment cannot be received after today.')],
            ArPaymentResult::REFUSAL_NO_BUSINESS_DATE => [self::FORM, __('The property has no open business date.')],
            ArPaymentResult::REFUSAL_PAYMENT_NOT_ALLOCATABLE => [self::FORM, __('This payment is void, so it cannot be allocated.')],
            ArPaymentResult::REFUSAL_INVOICE_NOT_ALLOCATABLE => [self::FORM, __('One of the invoices cannot take a payment. Reload the page and try again.')],
            ArPaymentResult::REFUSAL_EXCEEDS_INVOICE_BALANCE => [self::FORM, __('An amount is more than the invoice still owes.')],
            ArPaymentResult::REFUSAL_EXCEEDS_UNALLOCATED => [self::FORM, __('The amounts are more than the payment has left to allocate.')],
            ArPaymentResult::REFUSAL_ALREADY_REVERSED => [self::FORM, __('This allocation is already reversed.')],
            ArPaymentResult::REFUSAL_REASON_REQUIRED => ['reason', __('Enter a reason for the reversal.')],
            ArPaymentResult::REFUSAL_HAS_ALLOCATIONS => [self::FORM, __('Reverse the allocations of this payment first.')],
            ArPaymentResult::REFUSAL_ALREADY_VOID => [self::FORM, __('This payment is already void.')],
            ArPaymentResult::REFUSAL_MIGRATION_WINDOW_CLOSED => [self::FORM, __('The migration window is closed, so old system balances cannot be added.')],
            ArPaymentResult::REFUSAL_COMPANY_NOT_AVAILABLE => [self::FORM, __('This company is not active.')],
            ArPaymentResult::REFUSAL_DATE_NOT_BEFORE_GO_LIVE => ['received_on', __('The old receipt must be dated before the go-live date.')],
            default => [self::FORM, __('This could not be done.')],
        };
    }
}
