<?php

namespace App\Data\Platform;

use App\Data\Shared\BadgeData;
use App\Enums\SignInSessionStatus;
use App\Models\SignInEvent;
use App\Services\Account\NetworkNames;
use App\Services\Platform\SignInLogDirectory;
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
        public ?string $city,
        public ?string $region,
        public ?string $network,
        public ?string $os,
        public ?string $browser,
        public ?BadgeData $session,
        public ?string $lastActiveAt,
    ) {}

    /**
     * Expects the {@see RELATIONS} loaded. A row without a user is an attempt on an email that has no account. Times
     * are in Malaysia time ({@see SignInLogDirectory::TIME_ZONE}).
     */
    public static function fromModel(SignInEvent $event): self
    {
        $user = $event->user;
        $status = SignInSessionStatus::at($event->last_active_at, $event->signed_out_at, now());

        return new self(
            id: $event->id,
            occurredAt: $event->occurred_at->setTimezone(SignInLogDirectory::TIME_ZONE)->format('Y-m-d H:i'),
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
            city: $event->city,
            region: $event->region,
            network: app(NetworkNames::class)->name($event->asn),
            os: $event->os,
            browser: $event->browser,
            session: $status === null ? null : BadgeData::fromSignInSessionStatus($status),
            lastActiveAt: $event->last_active_at?->setTimezone(SignInLogDirectory::TIME_ZONE)->format('Y-m-d H:i'),
        );
    }
}
