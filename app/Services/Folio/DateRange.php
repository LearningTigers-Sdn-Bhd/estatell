<?php

namespace App\Services\Folio;

use Carbon\CarbonImmutable;

/**
 * Date ranges as the customer documents print them. Stateless.
 */
final class DateRange
{
    /**
     * "12–15 Oct 2026", "28 Oct – 2 Nov 2026" or, across years, both full dates. The Stay cell of the info strip.
     */
    public static function long(CarbonImmutable $from, CarbonImmutable $to): string
    {
        if ($from->isSameMonth($to)) {
            return $from->format('j').'–'.$to->format('j M Y');
        }

        return $from->isSameYear($to) ? $from->format('j M').' – '.$to->format('j M Y') : $from->format('j M Y').' – '.$to->format('j M Y');
    }

    /**
     * "12–15 Oct" or "28 Oct – 2 Nov", with the years only across years. A unit line of the Rooms block.
     */
    public static function short(CarbonImmutable $from, CarbonImmutable $to): string
    {
        if (! $from->isSameYear($to)) {
            return $from->format('j M Y').' – '.$to->format('j M Y');
        }

        return $from->isSameMonth($to) ? $from->format('j').'–'.$to->format('j M') : $from->format('j M').' – '.$to->format('j M');
    }
}
