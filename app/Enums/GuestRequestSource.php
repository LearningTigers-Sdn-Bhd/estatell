<?php

namespace App\Enums;

enum GuestRequestSource: string
{
    case InPerson = 'in_person';
    case Phone = 'phone';
    case Message = 'message';
    case Concierge = 'concierge';

    public function label(): string
    {
        return match ($this) {
            self::InPerson => __('In person'),
            self::Phone => __('Phone'),
            self::Message => __('Message'),
            self::Concierge => __('Concierge page'),
        };
    }

    /**
     * The sources staff pick on the form. Concierge is set only by the guest's own page.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function staffOptions(): array
    {
        return array_map(
            fn (self $source): array => ['value' => $source->value, 'label' => $source->label()],
            array_filter(self::cases(), fn (self $source): bool => $source !== self::Concierge),
        );
    }
}
