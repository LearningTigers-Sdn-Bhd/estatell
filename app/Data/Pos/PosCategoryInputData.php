<?php

namespace App\Data\Pos;

use Spatie\LaravelData\Data;

/**
 * The fields of a menu category form.
 */
class PosCategoryInputData extends Data
{
    public function __construct(
        public string $name,
        public int $transactionCodeId,
        public ?string $internalName = null,
        public string $colorToken = 'category-1',
        public int $sortOrder = 0,
        public bool $active = true,
    ) {}
}
