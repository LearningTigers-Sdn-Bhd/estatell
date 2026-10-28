<?php

namespace App\Services\Payments;

use App\Actions\Payments\PaymentResult;
use App\Enums\PaymentMethodType;
use App\Enums\SurchargeType;
use App\Models\PaymentMethod;
use App\Models\Reservation;
use App\Services\Catalogues\TaxCalculator;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Brick\Money\Money;
use InvalidArgumentException;

/**
 * Quotes a payment: the method's surcharge, the tax on it and the total to collect. It writes nothing. The Record
 * payment Dialog shows it before the user confirms, and `RecordPayment` quotes again inside its transaction.
 * See docs/14-payments/01-payments-and-refunds.md.
 */
class PaymentQuote
{
    public function __construct(private TaxCalculator $taxCalculator) {}

    /**
     * `$amount` is the amount paid on the bill (a decimal string). A Fixed surcharge is the method's value, a Percent
     * one that percent of the amount (rounded half up). An Old system or OTA collected payment never has one: that money
     * was not taken at the desk. The tax on the fee follows the code of the method's surcharge
     * charge item and the booker's nationality. A fee that rounds to 0.00 adds nothing.
     */
    public function quote(Reservation $reservation, PaymentMethod $method, string $amount): PaymentQuoteResult
    {
        if (preg_match('/^\d+(\.\d{1,2})?$/', $amount) !== 1 || (float) $amount <= 0) {
            return PaymentQuoteResult::refused(PaymentResult::REFUSAL_AMOUNT_INVALID);
        }

        $property = $reservation->property;
        $currency = $property->settings?->currency->getCurrencyCode()
            ?? throw new InvalidArgumentException('The property needs settings with a currency to quote a payment.');
        $paid = Money::of($amount, $currency);
        $surchargeType = in_array($method->type, [PaymentMethodType::OldSystem, PaymentMethodType::OtaCollected], true) ? SurchargeType::None : $method->surcharge_type;
        $surcharge = match ($surchargeType) {
            SurchargeType::None => Money::zero($currency),
            SurchargeType::Fixed => Money::of((string) $method->surcharge_value, $currency),
            SurchargeType::Percent => $paid->multipliedBy(BigDecimal::of((string) $method->surcharge_value)->dividedBy(100, 4), RoundingMode::HalfUp),
        };

        if ($surcharge->isZero()) {
            return PaymentQuoteResult::quoted($paid, $surcharge, null);
        }

        $item = $method->surchargeChargeItem
            ?? throw new InvalidArgumentException('A payment method with a surcharge needs a surcharge charge item.');
        $tax = $this->taxCalculator->calculate($property, $item->transactionCode, $surcharge, '1', $reservation->booker?->nationality_code);

        if (! $tax->isCalculated()) {
            return PaymentQuoteResult::refused((string) $tax->refusalReason);
        }

        return PaymentQuoteResult::quoted($paid, $surcharge, $tax);
    }
}
