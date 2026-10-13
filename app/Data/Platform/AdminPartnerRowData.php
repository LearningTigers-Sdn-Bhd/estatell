<?php

namespace App\Data\Platform;

use App\Data\Shared\BadgeData;
use App\Models\Partner;
use Illuminate\Support\Facades\Date;
use Spatie\LaravelData\Data;

class AdminPartnerRowData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public string $codePrefix,
        public int $userId,
        public ?string $email,
        public int $propertiesCount,
        public BadgeData $status,
        public ?string $temporaryPasswordExpiresAt,
        public ?string $suspendedAt,
        public string $createdAt,
    ) {}

    /**
     * Expects `properties_count` from `withCount` and `user_id`, `user_email` and `user_temporary_password_expires_at`
     * from `PartnerDirectory`.
     */
    public static function fromModel(Partner $partner): self
    {
        $expiresAt = $partner->getAttribute('user_temporary_password_expires_at');

        return new self(
            id: $partner->id,
            name: $partner->name,
            codePrefix: $partner->code_prefix,
            userId: (int) $partner->getAttribute('user_id'),
            email: $partner->getAttribute('user_email'),
            propertiesCount: (int) $partner->getAttribute('properties_count'),
            status: BadgeData::fromPartnerStatus($partner->status),
            temporaryPasswordExpiresAt: $expiresAt === null ? null : Date::parse($expiresAt)->toIso8601String(),
            suspendedAt: $partner->suspended_at?->toDateString(),
            createdAt: $partner->created_at->toDateString(),
        );
    }
}
