<?php

namespace App\Services\Property;

use App\Enums\Currency;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
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
        $cents = BigDecimal::of($amount)->abs()->multipliedBy(100)->toScale(0, RoundingMode::HalfUp)->toBigInteger();
        [$major, $minor] = $cents->quotientAndRemainder(100);

        $formatter = new NumberFormatter('en_GB', NumberFormatter::SPELLOUT);
        $formatter->setTextAttribute(NumberFormatter::DEFAULT_RULESET, '%spellout-numbering-verbose');

        if ($major->isZero() && ! $minor->isZero()) {
            return ucfirst($this->spell($formatter, (string) $minor)).' '.$currency->minorUnitWords().' only';
        }

        $words = $currency->wordsName().' '.$this->spell($formatter, (string) $major);

        if (! $minor->isZero()) {
            $words .= ' and '.$this->spell($formatter, (string) $minor).' '.$currency->minorUnitWords();
        }

        return $words.' only';
    }

    private function spell(NumberFormatter $formatter, string $number): string
    {
        return (string) $formatter->format((int) $number);
    }
}
