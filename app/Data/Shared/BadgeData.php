<?php

namespace App\Data\Shared;

use App\Enums\ArInvoiceStatus;
use App\Enums\CompanyStatus;
use App\Enums\FolioRowTag;
use App\Enums\FolioStatus;
use App\Enums\PropertyStatus;
use App\Enums\RatePlanStatus;
use App\Enums\ReservationStatus;
use App\Enums\Tone;
use App\Enums\UnitStatus;
use Spatie\LaravelData\Data;

/**
 * An enum value with its label and tone, for `StatusBadge`.
 */
class BadgeData extends Data
{
    public function __construct(
        public string $value,
        public string $label,
        public Tone $tone,
    ) {}

    public static function fromStatus(PropertyStatus $status): self
    {
        return new self($status->value, $status->label(), $status->tone());
    }

    public static function fromArInvoiceStatus(ArInvoiceStatus $status): self
    {
        return new self($status->value, $status->label(), $status->tone());
    }

    public static function fromCompanyStatus(CompanyStatus $status): self
    {
        return new self($status->value, $status->label(), $status->tone());
    }

    public static function fromFolioStatus(FolioStatus $status): self
    {
        return new self($status->value, $status->label(), $status->tone());
    }

    public static function fromFolioRowTag(FolioRowTag $tag): self
    {
        return new self($tag->value, $tag->label(), $tag->tone());
    }

    public static function fromRatePlanStatus(RatePlanStatus $status): self
    {
        return new self($status->value, $status->label(), $status->tone());
    }

    public static function fromReservationStatus(ReservationStatus $status): self
    {
        return new self($status->value, $status->label(), $status->tone());
    }

    public static function fromUnitStatus(UnitStatus $status): self
    {
        return new self($status->value, $status->label(), $status->tone());
    }
}
