<?php

namespace App\Services\EInvoice;

class MalaysiaStates
{
    public const string NOT_APPLICABLE = '17';

    /**
     * Prescribed LHDN Malaysian state codes (01-16 + 17 for outside Malaysia).
     *
     * @var array<array-key, string>
     */
    public const array CODES = [
        '01' => 'Johor',
        '02' => 'Kedah',
        '03' => 'Kelantan',
        '04' => 'Melaka',
        '05' => 'Negeri Sembilan',
        '06' => 'Pahang',
        '07' => 'Pulau Pinang',
        '08' => 'Perak',
        '09' => 'Perlis',
        '10' => 'Selangor',
        '11' => 'Terengganu',
        '12' => 'Sabah',
        '13' => 'Sarawak',
        '14' => 'Wilayah Persekutuan Kuala Lumpur',
        '15' => 'Wilayah Persekutuan Labuan',
        '16' => 'Wilayah Persekutuan Putrajaya',
        self::NOT_APPLICABLE => 'Not applicable (outside Malaysia)',
    ];

    /**
     * Legacy city fallbacks for past records before explicit state was captured.
     *
     * @var array<string, string>
     */
    public const array LEGACY_CITY_FALLBACK = [
        'johor bahru' => '01',
        'alor setar' => '02',
        'kota bharu' => '03',
        'melaka' => '04',
        'seremban' => '05',
        'kuantan' => '06',
        'george town' => '07',
        'georgetown' => '07',
        'ipoh' => '08',
        'kangar' => '09',
        'shah alam' => '10',
        'petaling jaya' => '10',
        'subang jaya' => '10',
        'klang' => '10',
        'kuala terengganu' => '11',
        'kota kinabalu' => '12',
        'sandakan' => '12',
        'tawau' => '12',
        'kuching' => '13',
        'miri' => '13',
        'sibu' => '13',
        'kuala lumpur' => '14',
        'labuan' => '15',
        'putrajaya' => '16',
    ];

    public static function isValid(?string $code): bool
    {
        return $code !== null && array_key_exists($code, self::CODES);
    }

    public static function nameFor(?string $code): ?string
    {
        return $code !== null ? (self::CODES[$code] ?? null) : null;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        $options = [];
        foreach (self::CODES as $code => $name) {
            $codeStr = (string) $code;
            $options[] = [
                'value' => $codeStr,
                'label' => $codeStr === self::NOT_APPLICABLE ? $name : "{$codeStr} - {$name}",
            ];
        }

        return $options;
    }

    public static function resolve(?string $stateCode = null, ?string $city = null, ?string $countryCode = null): string
    {
        if (self::isValid($stateCode)) {
            return (string) $stateCode;
        }

        if ($countryCode !== null && strtoupper(trim($countryCode)) !== 'MY' && strtoupper(trim($countryCode)) !== 'MYS') {
            return self::NOT_APPLICABLE;
        }

        if ($city !== null) {
            $cityLower = strtolower(trim($city));
            if (isset(self::LEGACY_CITY_FALLBACK[$cityLower])) {
                return self::LEGACY_CITY_FALLBACK[$cityLower];
            }
        }

        return self::NOT_APPLICABLE;
    }

    /**
     * The LHDN code for a Malaysian state given as a code or a name, such as "Sabah", "Penang" or "Kuala Lumpur".
     * Null when it is not a Malaysian state.
     */
    public static function codeFor(?string $state): ?string
    {
        $state = trim((string) $state);
        if ($state === '') {
            return null;
        }

        if (self::isValid($state) && $state !== self::NOT_APPLICABLE) {
            return $state;
        }

        $name = strtolower((string) preg_replace('/^(wilayah persekutuan|w\.?\s?p\.?|federal territory( of)?)\s+/i', '', $state));
        $aliases = ['penang' => '07', 'pulau pinang' => '07', 'malacca' => '04', 'kl' => '14', 'kuala lumpur' => '14', 'labuan' => '15', 'putrajaya' => '16', 'negri sembilan' => '05'];
        if (isset($aliases[$name])) {
            return $aliases[$name];
        }

        foreach (self::CODES as $code => $label) {
            if ((string) $code !== self::NOT_APPLICABLE && strtolower((string) preg_replace('/^Wilayah Persekutuan\s+/', '', $label)) === $name) {
                return (string) $code;
            }
        }

        return null;
    }
}
