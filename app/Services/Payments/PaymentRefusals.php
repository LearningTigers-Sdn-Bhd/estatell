<?php

namespace App\Services\Payments;

use App\Actions\Payments\PaymentResult;
use App\Services\Catalogues\TaxCalculator;

/**
 * Turns the refusal codes of `RecordPayment`, `RefundPayment` and `PaymentQuote` into a message and the form field it
 * belongs to. A refusal with no matching field uses `form`, a message for the whole form.
 * See docs/14-payments/01-payments-and-refunds.md.
 */
class PaymentRefusals
{
    public const string FORM = 'form';

    /**
     * The errors to flash back to a form, keyed by field name.
     *
     * @return array<string, string>
     */
    public function errors(string $code): array
    {
        [$field, $message] = $this->entry($code);

        return [$field => $message];
    }

    /**
     * The message alone, for the quote answer.
     */
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
            PaymentResult::REFUSAL_NO_BUSINESS_DATE => [self::FORM, __('The property has no open business date, so no payment can be recorded.')],
            PaymentResult::REFUSAL_FOLIO_NOT_OPEN => [self::FORM, __('This folio is closed. Reopen it on the folio tab first, then try again.')],
            PaymentResult::REFUSAL_DEPOSIT_ON_UNIT_ONLY => [self::FORM, __('A security deposit stays on the unit\'s own folio. Record it on the unit folio.')],
            PaymentResult::REFUSAL_ADVANCE_ON_MASTER => [self::FORM, __('An advance for this room goes on the Master folio, because the Master folio pays the room charges. Record it there.')],
            PaymentResult::REFUSAL_PURPOSE_NOT_OFFERED => [self::FORM, __('A third party folio takes settlement payments only.')],
            PaymentResult::REFUSAL_METHOD_NOT_AVAILABLE => ['payment_method_id', __('This payment method is not available.')],
            PaymentResult::REFUSAL_AMOUNT_INVALID => ['amount', __('Enter an amount greater than 0.')],
            PaymentResult::REFUSAL_REFERENCE_REQUIRED => ['reference', __('This payment method needs a reference.')],
            PaymentResult::REFUSAL_SERVICE_DATE_INVALID => ['service_date', __('Pick a day from the booking date to today.')],
            PaymentResult::REFUSAL_PAYMENT_NOT_FOUND => [self::FORM, __('This payment could not be found. Close the form and try again.')],
            PaymentResult::REFUSAL_NOT_REFUNDABLE => [self::FORM, __('Only a completed payment can be refunded.')],
            PaymentResult::REFUSAL_REFUND_EXCEEDS_PAYMENT => ['amount', __('The refund is more than what is left of this payment.')],
            PaymentResult::REFUSAL_REFUND_METHOD_MISMATCH => ['payment_method_id', __('Only a cash payment can be refunded in cash. Refund it to the original method, or to another non-cash method.')],
            PaymentResult::REFUSAL_OTHER_METHOD_NOT_ALLOWED => ['payment_method_id', __('You can only refund to the original method. Ask a manager to use another one.')],
            PaymentResult::REFUSAL_REASON_REQUIRED => ['reason', __('Add a reason for refunding to another method.')],
            PaymentResult::REFUSAL_VOID_NOT_SAME_DAY => [self::FORM, __('This payment was taken on an earlier day. Refund it instead of voiding it.')],
            PaymentResult::REFUSAL_VOID_HAS_REFUND => [self::FORM, __('This payment already has a refund, so it can\'t be voided.')],
            PaymentResult::REFUSAL_CREDIT_NOT_REFUNDABLE => ['amount', __('This folio has no credit that can be refunded.')],
            TaxCalculator::REFUSAL_NATIONALITY_REQUIRED => [self::FORM, __('The surcharge has a tax that depends on nationality. Add the booker\'s nationality to the guest first.')],
            TaxCalculator::REFUSAL_INCLUSIVE_TAX_EXCEEDS_AMOUNT => [self::FORM, __('The tax in the surcharge is more than the surcharge.')],
            default => [self::FORM, __('This could not be done.')],
        };
    }
}
