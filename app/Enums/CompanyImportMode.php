<?php

namespace App\Enums;

/**
 * Which columns Bulk add companies shows and its template has. Simple matches the add sheet; Detailed adds the tax
 * numbers and billing address. See docs/15-city-ledger/01-companies.md#bulk-add.
 */
enum CompanyImportMode: string
{
    case Simple = 'simple';
    case Detailed = 'detailed';

    /**
     * File column => company field.
     *
     * @var array<string, string>
     */
    private const array SIMPLE_COLUMNS = [
        'name' => 'name',
        'type' => 'type',
        'market' => 'market',
        'email' => 'contact_email',
        'phone' => 'contact_phone',
        'credit_limit' => 'credit_limit',
        'terms_days' => 'payment_terms_days',
        'direct_billing' => 'direct_bill_enabled',
        'portal_email' => 'portal_email',
        'cancel_days' => 'portal_cancel_cutoff_days',
    ];

    /**
     * @var array<string, string>
     */
    private const array DETAILED_COLUMNS = [
        'brn' => 'brn',
        'tin' => 'tin',
        'sst' => 'sst_registration_number',
        'address_line1' => 'billing_address_line1',
        'address_line2' => 'billing_address_line2',
        'city' => 'billing_city',
        'postcode' => 'billing_postal_code',
        'state' => 'billing_state_code',
        'country' => 'billing_country_code',
    ];

    public function label(): string
    {
        return match ($this) {
            self::Simple => __('Simple'),
            self::Detailed => __('Detailed'),
        };
    }

    /**
     * The file columns of this mode, in order, with the company field each fills.
     *
     * @return array<string, string>
     */
    public function columns(): array
    {
        return match ($this) {
            self::Simple => self::SIMPLE_COLUMNS,
            self::Detailed => [...self::SIMPLE_COLUMNS, ...self::DETAILED_COLUMNS],
        };
    }

    /**
     * The mode a file needs: Detailed when it has any column only Detailed shows.
     *
     * @param  list<string>  $headers
     */
    public static function forHeaders(array $headers): self
    {
        return array_intersect($headers, array_keys(self::DETAILED_COLUMNS)) === [] ? self::Simple : self::Detailed;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $mode): array => ['value' => $mode->value, 'label' => $mode->label()],
            self::cases(),
        );
    }
}
