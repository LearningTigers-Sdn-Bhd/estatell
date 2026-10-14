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
     * @return list<string>
     */
    public function codes(): array
    {
        return array_column($this->options(), 'value');
    }
}
