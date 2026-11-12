<?php

namespace App\Enums;

/**
 * An email to a guest whose subject and message the property can rewrite in Settings › Emails. Each case holds its
 * default text and the placeholders staff may use. The button and the details table are fixed, so a property cannot
 * remove the link the guest needs.
 */
enum GuestEmail: string
{
    case EInvoice = 'e_invoice';

    public function label(): string
    {
        return match ($this) {
            self::EInvoice => __('E-invoice for the guest'),
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::EInvoice => __('Sent when staff press Email to guest on an e-invoice LHDN accepted. A View e-invoice button and the e-invoice details are added below your message.'),
        };
    }

    public function defaultSubject(): string
    {
        return match ($this) {
            self::EInvoice => __('Your e-invoice from {hotel_name}'),
        };
    }

    public function defaultBody(): string
    {
        return match ($this) {
            self::EInvoice => __("Dear {guest_name},\n\nThank you for staying with us. Your e-invoice {invoice_number} for {amount} has been accepted by LHDN, the Malaysian tax authority.\n\nPress the button below to view it on MyInvois. You can download it or keep the link for your records.\n\nIf any details are wrong, reply to this email and we will correct it."),
        };
    }

    /**
     * The placeholders staff may type, with what each becomes and an example.
     *
     * @return list<array{key: string, label: string, example: string}>
     */
    public function placeholders(): array
    {
        return match ($this) {
            self::EInvoice => [
                ['key' => 'guest_name', 'label' => __('Name on the e-invoice'), 'example' => 'Ahmad Razak'],
                ['key' => 'hotel_name', 'label' => __('Your property name'), 'example' => 'Boreno Hotel'],
                ['key' => 'invoice_number', 'label' => __('E-invoice number'), 'example' => 'INV-2026-0012'],
                ['key' => 'amount', 'label' => __('Total with tax'), 'example' => 'MYR 754.00'],
                ['key' => 'stay_dates', 'label' => __('Stay dates'), 'example' => '20 Oct – 21 Oct 2026'],
            ],
        };
    }
}
