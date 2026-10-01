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

    public function label(): string
    {
        return match ($this) {
            self::Reservation => __('Reservation'),
            self::Folio => __('Folio'),
            self::Invoice => __('Invoice'),
            self::CreditNote => __('Credit note'),
            self::Receipt => __('Receipt'),
            self::RegistrationCard => __('Registration card'),
        };
    }

    /**
     * The one-digit type code in a document reference. It matches wastays; codes 4 and 6 stay unused.
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
