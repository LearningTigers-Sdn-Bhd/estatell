<?php

namespace App\Data\NightAudit;

use App\Enums\BusinessDateStatus;
use App\Models\NightAudit;
use App\Models\Property;
use App\Services\NightAudit\AuditReadiness;
use App\Services\NightAudit\CurrentBusinessDate;
use Spatie\LaravelData\Data;

/**
 * Everything the Run Night Audit sheet shows: the date it would close, what to fix first, what to look at, and the
 * progress of the audit of that date or of the last one that finished.
 */
class NightAuditSheetData extends Data
{
    /**
     * @param  string|null  $date  `Y-m-d`, the current business date. Null while the property has no business date.
     * @param  list<ReadinessItemData>  $blockers
     * @param  list<ReadinessItemData>  $warnings
     */
    public function __construct(
        public ?string $date,
        public ?string $nextDate,
        public ?BusinessDateStatus $status,
        public array $blockers,
        public array $warnings,
        public ?NightAuditProgressData $audit,
        public bool $canForce,
    ) {}

    public static function forProperty(Property $property, AuditReadiness $readiness, CurrentBusinessDate $currentBusinessDate, bool $canForce): self
    {
        $businessDate = $currentBusinessDate->get($property);
        $items = array_map(fn ($item): ReadinessItemData => ReadinessItemData::fromItem($item, $property), $readiness->items($property));
        $audit = $businessDate?->nightAudit ?? NightAudit::query()->where('property_id', $property->id)->latest('id')->first();

        return new self(
            date: $businessDate?->date->toDateString(),
            nextDate: $businessDate?->date->addDay()->toDateString(),
            status: $businessDate?->status,
            blockers: array_values(array_filter($items, fn (ReadinessItemData $item): bool => $item->blocker)),
            warnings: array_values(array_filter($items, fn (ReadinessItemData $item): bool => ! $item->blocker)),
            audit: $audit === null ? null : NightAuditProgressData::fromAudit($audit->loadMissing('businessDate')),
            canForce: $canForce,
        );
    }
}
