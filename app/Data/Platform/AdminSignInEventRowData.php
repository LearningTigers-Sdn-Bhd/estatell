<?php

namespace App\Data\Platform;

use App\Data\Shared\BadgeData;
use App\Models\SignInEvent;
use Spatie\LaravelData\Data;

class AdminSignInEventRowData extends Data
{
    /** The relations {@see fromModel()} reads. */
    public const array RELATIONS = [
        'user:id,name,email,property_id,partner_id,is_admin',
        'user.property:id,name',
        'user.partner:id,name',
    ];

    public function __construct(
        public int $id,
        public string $occurredAt,
        public ?string $userName,
        public ?string $userEmail,
        public ?string $userWhere,
        public BadgeData $result,
        public string $ipAddress,
        public ?string $countryCode,
        public ?string $os,
        public ?string $browser,
    ) {}

    /**
     * Expects the {@see RELATIONS} loaded. A row without a user is an attempt on an email that has no account.
     */
    public static function fromModel(SignInEvent $event): self
    {
        $user = $event->user;

        return new self(
            id: $event->id,
            occurredAt: $event->occurred_at->format('Y-m-d H:i'),
            userName: $user?->name,
            userEmail: $user?->email,
            userWhere: match (true) {
                $user === null => null,
                $user->is_admin => __('Platform admin'),
                $user->partner !== null => $user->partner->name,
                default => $user->property?->name,
            },
            result: BadgeData::fromSignInResult($event->result),
            ipAddress: $event->ip_address,
            countryCode: $event->country_code,
            os: $event->os,
            browser: $event->browser,
        );
    }
}
