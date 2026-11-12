<?php

namespace App\Services\EInvoice;

class PhoneFormatter
{
    public const string DEFAULT_PHONE = '+60123456789';

    public static function format(?string $phone): string
    {
        if (empty($phone)) {
            return self::DEFAULT_PHONE;
        }

        $cleaned = trim($phone);
        if (str_starts_with($cleaned, '+')) {
            return $cleaned;
        }

        $digits = preg_replace('/\D/', '', $cleaned);

        return $digits !== '' ? "+{$digits}" : self::DEFAULT_PHONE;
    }
}
