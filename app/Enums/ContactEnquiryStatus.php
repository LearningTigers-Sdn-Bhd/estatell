<?php

namespace App\Enums;

enum ContactEnquiryStatus: string
{
    case New = 'new';
    case Contacted = 'contacted';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::New => __('New'),
            self::Contacted => __('Contacted'),
            self::Closed => __('Closed'),
        };
    }

    public function tone(): Tone
    {
        return match ($this) {
            self::New => Tone::Info,
            self::Contacted => Tone::Warning,
            self::Closed => Tone::Neutral,
        };
    }

    /**
     * An enquiry moves forward, and a closed one can be reopened as New or Contacted.
     */
    public function canTransitionTo(self $status): bool
    {
        return match ($this) {
            self::New => $status !== self::New,
            self::Contacted => $status !== self::Contacted,
            self::Closed => $status !== self::Closed,
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $status): array => ['value' => $status->value, 'label' => $status->label()],
            self::cases(),
        );
    }
}
