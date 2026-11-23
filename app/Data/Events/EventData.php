<?php

namespace App\Data\Events;

use App\Data\Guests\GuestOptionData;
use App\Data\Shared\BadgeData;
use App\Enums\EventStatus;
use App\Models\Event;
use Spatie\LaravelData\Data;

/**
 * The event page header, its summary and the Edit event form. The `can*` flags already combine the status and the
 * user's `events.manage`.
 */
class EventData extends Data
{
    public function __construct(
        public int $id,
        public string $number,
        public string $name,
        public string $payerName,
        public ?string $payerPhone,
        public ?GuestOptionData $payerGuest,
        public ?int $payerCompanyId,
        public string $startsOn,
        public string $endsOn,
        public ?string $notes,
        public BadgeData $status,
        public bool $canEdit,
        public bool $canConfirm,
        public bool $canCancel,
    ) {}

    /**
     * Expects `payerGuest` and `payerCompany` to be loaded.
     */
    public static function fromModel(Event $event, bool $canManage): self
    {
        $live = in_array($event->status, [EventStatus::Tentative, EventStatus::Confirmed], true);

        return new self(
            id: $event->id,
            number: $event->number,
            name: $event->name,
            payerName: $event->payerGuest->full_name ?? $event->payerCompany->name ?? '',
            payerPhone: $event->payerGuest->phone ?? $event->payerCompany?->contact_phone,
            payerGuest: $event->payerGuest === null ? null : GuestOptionData::fromModel($event->payerGuest),
            payerCompanyId: $event->payer_company_id,
            startsOn: $event->starts_on->toDateString(),
            endsOn: $event->ends_on->toDateString(),
            notes: $event->notes,
            status: BadgeData::fromEventStatus($event->status),
            canEdit: $canManage && $live,
            canConfirm: $canManage && $event->status->canTransitionTo(EventStatus::Confirmed) && $event->status === EventStatus::Tentative,
            canCancel: $canManage && $event->status->canTransitionTo(EventStatus::Cancelled),
        );
    }
}
