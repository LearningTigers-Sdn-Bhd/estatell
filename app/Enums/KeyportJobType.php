<?php

namespace App\Enums;

/**
 * What a Keyport job asks the card reader to do. Guest cards only: there is no case for master, building or other
 * special cards, so Estatell cannot queue one even by mistake.
 */
enum KeyportJobType: string
{
    case Issue = 'guest_card.issue';
    case Rewrite = 'guest_card.rewrite';
    case Erase = 'guest_card.erase';
    case Read = 'guest_card.read';

    public function label(): string
    {
        return match ($this) {
            self::Issue => __('Make key card'),
            self::Rewrite => __('Rewrite key card'),
            self::Erase => __('Erase key card'),
            self::Read => __('Read key card'),
        };
    }

    /**
     * A write that fails halfway can leave the card in an unknown state, so it is never offered twice.
     */
    public function isWrite(): bool
    {
        return $this !== self::Read;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $type): array => ['value' => $type->value, 'label' => $type->label()], self::cases());
    }
}
