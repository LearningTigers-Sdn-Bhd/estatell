<?php

namespace App\Data\Pos;

use Spatie\LaravelData\Data;

/**
 * The fields of a modifier group form, with its choices and the items that offer it.
 */
class PosModifierGroupInputData extends Data
{
    /**
     * @param  list<PosModifierOptionInputData>  $options
     * @param  list<int>  $itemIds
     */
    public function __construct(
        public string $name,
        public array $options,
        public array $itemIds = [],
        public int $minSelect = 0,
        public ?int $maxSelect = null,
        public int $maxPerOption = 1,
        public int $freeIncludedCount = 0,
        public int $sortOrder = 0,
        public bool $active = true,
    ) {}
}
