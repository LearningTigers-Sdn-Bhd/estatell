<?php

namespace App\Enums;

enum OwnerSetupMethod: string
{
    case EmailLink = 'email_link';
    case InitialPassword = 'initial_password';

    public function label(): string
    {
        return match ($this) {
            self::EmailLink => __('Email setup link'),
            self::InitialPassword => __('Set initial password'),
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::EmailLink => __('Email the owner a secure setup link that expires after seven days.'),
            self::InitialPassword => __('Set a password now and share it with the owner separately. The owner will receive the normal email-verification message.'),
        };
    }

    /**
     * @return list<array{value: string, label: string, description: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $method): array => [
                'value' => $method->value,
                'label' => $method->label(),
                'description' => $method->description(),
            ],
            self::cases(),
        );
    }
}
