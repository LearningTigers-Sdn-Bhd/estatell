<?php

namespace App\Enums;

enum EInvoiceEnvironment: string
{
    case Mock = 'mock';
    case Sandbox = 'sandbox';
    case Production = 'production';

    public function label(): string
    {
        return match ($this) {
            self::Mock => __('Mock (Local test responses)'),
            self::Sandbox => __('Sandbox (LHDN Pre-production)'),
            self::Production => __('Production (Live MyInvois)'),
        };
    }

    public function apiUrl(): string
    {
        return match ($this) {
            self::Mock => 'https://preprod-api.myinvois.hasil.gov.my',
            self::Sandbox => 'https://preprod-api.myinvois.hasil.gov.my',
            self::Production => 'https://api.myinvois.hasil.gov.my',
        };
    }

    public function portalUrl(): string
    {
        return match ($this) {
            self::Mock => 'https://preprod.myinvois.hasil.gov.my',
            self::Sandbox => 'https://preprod.myinvois.hasil.gov.my',
            self::Production => 'https://myinvois.hasil.gov.my',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $env): array => ['value' => $env->value, 'label' => $env->label()],
            self::cases(),
        );
    }
}
