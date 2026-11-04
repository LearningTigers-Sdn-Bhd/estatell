<?php

namespace App\Data\BookingEngine;

use App\Models\SiteTemplateSlot;
use Spatie\LaravelData\Data;

/**
 * One image slot of a template, as the admin form edits it.
 */
class SiteTemplateSlotData extends Data
{
    public function __construct(
        public string $key,
        public string $label,
        public bool $required,
    ) {}

    public static function fromModel(SiteTemplateSlot $slot): self
    {
        return new self($slot->key, $slot->label, $slot->required);
    }
}
