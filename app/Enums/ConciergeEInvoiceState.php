<?php

namespace App\Enums;

/**
 * Where a guest's e-invoice request stands on the Concierge e-invoice page. `ConciergeEInvoice` picks it.
 */
enum ConciergeEInvoiceState: string
{
    case AfterCheckOut = 'after_check_out';
    case Unavailable = 'unavailable';
    case WindowClosed = 'window_closed';
    case InSummary = 'in_summary';
    case Open = 'open';
    case Processing = 'processing';
    case Valid = 'valid';

    public function title(): string
    {
        return match ($this) {
            self::AfterCheckOut => __('Available after check-out'),
            self::Unavailable => __('Ask the front desk'),
            self::WindowClosed => __('The request period has ended'),
            self::InSummary => __('Already reported'),
            self::Open => __('Request your e-invoice'),
            self::Processing => __('Your e-invoice is being prepared'),
            self::Valid => __('Your e-invoice is ready'),
        };
    }

    public function message(): string
    {
        return match ($this) {
            self::AfterCheckOut => __('You can request an e-invoice once your stay is checked out and the bill is closed.'),
            self::Unavailable => __('This property cannot send an e-invoice from this page. Please ask the front desk.'),
            self::WindowClosed => __('An e-invoice can be requested until the end of the month your bill was closed. Please ask the front desk.'),
            self::InSummary => __('Your stay is already part of the property\'s monthly e-invoice. Ask the front desk if you need your own.'),
            self::Open => __('Fill in the details to put on the e-invoice. LHDN checks that the tax number matches your ID.'),
            self::Processing => __('We are sending it to LHDN. This usually takes a minute. Refresh to check.'),
            self::Valid => __('LHDN has validated your e-invoice. Open it to view, print or save it.'),
        };
    }

    /**
     * Whether the stay screen links to the e-invoice page for this state.
     */
    public function isOffered(): bool
    {
        return in_array($this, [self::Open, self::Processing, self::Valid, self::InSummary], true);
    }
}
