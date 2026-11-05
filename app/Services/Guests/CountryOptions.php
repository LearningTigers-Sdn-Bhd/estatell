<?php

namespace App\Services\Guests;

use Locale;
use ResourceBundle;

/**
 * Country names for pickers, from the PHP intl extension. Keys are ISO 3166-1 alpha-2 codes.
 */
class CountryOptions
{
    /**
     * Region codes in the intl data that are not countries.
     */
    private const array NOT_COUNTRIES = ['AC', 'CP', 'DG', 'EA', 'EU', 'EZ', 'IC', 'TA', 'UN', 'ZZ', 'QO', 'XA', 'XB'];

    /**
     * @return list<array{value: string, label: string}>
     */
    public function options(): array
    {
        $bundle = ResourceBundle::create(app()->getLocale(), 'ICUDATA-region') ?? ResourceBundle::create('en', 'ICUDATA-region');
        $options = [];

        foreach ($bundle['Countries'] as $code => $name) {
            if (preg_match('/^[A-Z]{2}$/', (string) $code) === 1 && ! in_array($code, self::NOT_COUNTRIES, true)) {
                $options[] = ['value' => (string) $code, 'label' => (string) $name];
            }
        }

        usort($options, fn (array $a, array $b): int => strcmp($a['label'], $b['label']));

        return $options;
    }

    /**
     * The same options with Malaysia first, for the property pickers.
     *
     * @return list<array{value: string, label: string}>
     */
    public function optionsWithMalaysiaFirst(): array
    {
        $options = $this->options();

        return [
            ...array_values(array_filter($options, fn (array $option): bool => $option['value'] === 'MY')),
            ...array_values(array_filter($options, fn (array $option): bool => $option['value'] !== 'MY')),
        ];
    }

    /**
     * The country name for a code, or null when the code is empty.
     */
    public function name(?string $code): ?string
    {
        if ($code === null || $code === '') {
            return null;
        }

        $name = Locale::getDisplayRegion('-'.$code, app()->getLocale());

        return $name === false ? null : $name;
    }

    /**
     * The ISO 3166-1 alpha-3 code for an alpha-2 code (MY becomes MYS), from the intl data.
     * An alpha-3 code is returned as it is. Null when the code is empty or unknown.
     */
    public function alpha3(?string $code): ?string
    {
        $code = strtoupper(trim((string) $code));

        if ($code === '') {
            return null;
        }

        $alpha3ByAlpha2 = $this->alpha3ByAlpha2();

        if (strlen($code) === 3) {
            return in_array($code, $alpha3ByAlpha2, true) ? $code : null;
        }

        return $alpha3ByAlpha2[$code] ?? null;
    }

    /**
     * ICU codeMappings rows are [alpha-2, numeric, alpha-3].
     *
     * @return array<string, string>
     */
    private function alpha3ByAlpha2(): array
    {
        static $map = null;

        if ($map !== null) {
            return $map;
        }

        $map = [];
        $rows = ResourceBundle::create('supplementalData', 'ICUDATA', false)?->get('codeMappings');

        foreach ($rows ?? [] as $row) {
            $alpha2 = (string) $row->get(0);
            $alpha3 = (string) $row->get(2);

            if (preg_match('/^[A-Z]{2}$/', $alpha2) === 1 && preg_match('/^[A-Z]{3}$/', $alpha3) === 1) {
                $map[$alpha2] = $alpha3;
            }
        }

        return $map;
    }

    /**
     * @return list<string>
     */
    public function codes(): array
    {
        return array_column($this->options(), 'value');
    }
}
