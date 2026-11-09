<?php

namespace App\Data\Platform;

use App\Data\Shared\BadgeData;
use App\Enums\MailActorType;
use App\Models\MailEvent;
use Spatie\LaravelData\Data;

class AdminMailEventRowData extends Data
{
    /** The relations {@see fromModel()} reads. */
    public const array RELATIONS = [
        'property:id,name',
        'actor:id,name,partner_id',
        'actor.partner:id,name',
        'actor.companyUsers:id,user_id,property_id,company_id',
        'actor.companyUsers.company:id,name',
    ];

    public function __construct(
        public int $id,
        public string $sentAt,
        public ?string $triggeredByTitle,
        public ?string $triggeredByDetail,
        public string $recipient,
        public int $recipientCount,
        public string $subject,
        public ?string $notificationType,
        public BadgeData $status,
        public ?string $providerMessageId,
        public ?string $error,
        public ?string $propertyName,
    ) {}

    /**
     * Expects the {@see RELATIONS} loaded.
     */
    public static function fromModel(MailEvent $event): self
    {
        [$triggeredByTitle, $triggeredByDetail] = self::triggeredBy($event);

        return new self(
            id: $event->id,
            sentAt: $event->sent_at->format('Y-m-d H:i'),
            triggeredByTitle: $triggeredByTitle,
            triggeredByDetail: $triggeredByDetail,
            recipient: $event->recipient,
            recipientCount: $event->recipient_count,
            subject: $event->subject,
            notificationType: $event->notification_type,
            status: BadgeData::fromMailStatus($event->status),
            providerMessageId: $event->provider_message_id,
            error: $event->error,
            propertyName: $event->property?->name,
        );
    }

    /**
     * The two lines of the "Triggered by" cell. The first is the property when it is known, the rest says who.
     *
     * @return array{0: string|null, 1: string|null}
     */
    private static function triggeredBy(MailEvent $event): array
    {
        $person = $event->actor?->name;

        $who = array_values(array_filter(match ($event->actor_type) {
            null => [],
            MailActorType::Property, MailActorType::Admin => [$event->actor_type->label(), $person],
            MailActorType::Partner => [$event->actor_type->label(), $event->actor?->partner?->name, $person],
            MailActorType::Company => [$event->actor_type->label(), $event->actor?->companyUsers->firstWhere('property_id', $event->property_id)?->company?->name],
            MailActorType::System, MailActorType::Guest => [$event->actor_type->label()],
        }));

        $title = $event->property->name ?? array_shift($who);

        return [$title, $who === [] ? null : implode(' · ', $who)];
    }
}
