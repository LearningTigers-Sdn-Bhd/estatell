<?php

namespace App\Services\Property;

use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;
use Brick\Money\Currency;
use NumberFormatter;

/**
 * An amount in English words for a customer document, for example "Ringgit Malaysia one thousand five hundred and
 * four only". It uses the PHP intl spellout rules and writes nothing. See docs/02-property/05-printing-settings.md.
 */
class AmountInWords
{
    /**
     * @param  string  $amount  A decimal string, for example "1504.50". A negative amount is spelled as its size.
     */
    public function for(string $amount, Currency $currency): string
    {
        $decimals = $currency->getDefaultFractionDigits();
        $scale = BigInteger::of(10)->power($decimals);
        $units = BigDecimal::of($amount)->abs()->toScale($decimals, RoundingMode::HalfUp)->getUnscaledValue();
        [$major, $minor] = $units->quotientAndRemainder($scale);

        $formatter = new NumberFormatter('en_GB', NumberFormatter::SPELLOUT);
        $formatter->setTextAttribute(NumberFormatter::DEFAULT_RULESET, '%spellout-numbering-verbose');

        $minorUnit = CurrencyWords::minorUnit($currency);

        if ($major->isZero() && ! $minor->isZero() && $minorUnit !== null) {
            return ucfirst($this->spell($formatter, (string) $minor)).' '.$minorUnit.' only';
        }

        $words = CurrencyWords::name($currency).' '.$this->spell($formatter, (string) $major);

        if (! $minor->isZero()) {
            $words .= ' and '.($minorUnit !== null
                ? $this->spell($formatter, (string) $minor).' '.$minorUnit
                : str_pad((string) $minor, $decimals, '0', STR_PAD_LEFT).'/'.$scale);
        }

        return $words.' only';
    }

    private function spell(NumberFormatter $formatter, string $number): string
    {
        return (string) $formatter->format((int) $number);
    }
}
