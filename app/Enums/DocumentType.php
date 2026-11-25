<?php

namespace App\Enums;

enum DocumentType: string
{
    case Reservation = 'reservation';
    case Folio = 'folio';
    case Invoice = 'invoice';
    case CreditNote = 'credit_note';
    case Receipt = 'receipt';
    case RegistrationCard = 'registration_card';
    case Event = 'event';
    case PosOrder = 'pos_order';
    case PosShift = 'pos_shift';

    public function label(): string
    {
        return match ($this) {
            self::Reservation => __('Reservation'),
            self::Folio => __('Folio'),
            self::Invoice => __('Invoice'),
            self::CreditNote => __('Credit note'),
            self::Receipt => __('Receipt'),
            self::RegistrationCard => __('Registration card'),
            self::Event => __('Event'),
            self::PosOrder => __('POS order'),
            self::PosShift => __('POS shift'),
        };
    }

    /**
     * The type code in a document reference: one digit for the wastays types (codes 4 and 6 stay unused), Event (9) is estatell's own, and the Native POS types take two digits (10, 11).
     */
    public function code(): int
    {
        return match ($this) {
            self::Reservation => 1,
            self::RegistrationCard => 2,
            self::Folio => 3,
            self::Receipt => 5,
            self::Invoice => 7,
            self::CreditNote => 8,
            self::Event => 9,
            self::PosOrder => 10,
            self::PosShift => 11,
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $type): array => ['value' => $type->value, 'label' => $type->label()],
            self::cases(),
        );
    }
}
