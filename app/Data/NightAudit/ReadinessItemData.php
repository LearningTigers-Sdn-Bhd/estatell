<?php

namespace App\Data\NightAudit;

use App\Enums\AuditCheck;
use App\Models\Property;
use App\Services\NightAudit\ReadinessItem;
use Spatie\LaravelData\Data;

/**
 * One row of the night audit sheet. `assignUrl` is set only when the row can be fixed in place.
 */
class ReadinessItemData extends Data
{
    public function __construct(
        public AuditCheck $check,
        public string $checkLabel,
        public bool $blocker,
        public string $reference,
        public ?string $guest,
        public string $detail,
        public ?string $message,
        public string $url,
        public ?string $assignUrl,
    ) {}

    public static function fromItem(ReadinessItem $item, Property $property): self
    {
        $parameters = ['property' => $property->code, 'reservation' => $item->reservationId];

        return new self(
            check: $item->check,
            checkLabel: $item->check->label(),
            blocker: $item->check->isBlocker(),
            reference: $item->reference,
            guest: $item->guest,
            detail: $item->detail,
            message: $item->message,
            url: route('reservations.show', $parameters, absolute: false),
            assignUrl: $item->check === AuditCheck::InHouseWithoutRoom && $item->reservationUnitId !== null
                ? route('night-audit.units.assign', [...$parameters, 'unit' => $item->reservationUnitId], absolute: false)
                : null,
        );
    }
}
