<?php

namespace App\Enums;

enum GuestMatchReason: string
{
    case Phone = 'phone';
    case Email = 'email';
    case IdNumber = 'id_number';
    case NameAndBirthDate = 'name_and_birth_date';
    case Name = 'name';

    public function label(): string
    {
        return match ($this) {
            self::Phone => __('Same phone'),
            self::Email => __('Same email'),
            self::IdNumber => __('Same ID number'),
            self::NameAndBirthDate => __('Same name and date of birth'),
            self::Name => __('Same name'),
        };
    }

    /**
     * A name alone is weak: two different people can share a name.
     */
    public function isStrong(): bool
    {
        return $this !== self::Name;
    }
}
