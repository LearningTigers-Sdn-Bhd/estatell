<?php

namespace App\Data\CityLedger;

use Spatie\LaravelData\Data;

/**
 * A company's credit figures for its page: open AR, in-house balance, exposure, the limit and the headroom (both null
 * with no limit), and whether the exposure is above the limit.
 */
class CreditExposureData extends Data
{
    public function __construct(
        public string $openAr,
        public string $inHouse,
        public string $exposure,
        public ?string $limit,
        public ?string $headroom,
        public bool $overLimit,
    ) {}

    /**
     * @param  array{openAr: string, inHouse: string, exposure: string, limit: string|null, headroom: string|null}  $figures
     */
    public static function fromFigures(array $figures, bool $overLimit): self
    {
        return new self($figures['openAr'], $figures['inHouse'], $figures['exposure'], $figures['limit'], $figures['headroom'], $overLimit);
    }
}
