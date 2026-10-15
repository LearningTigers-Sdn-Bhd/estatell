<?php

namespace App\Services\Onboarding;

use App\Data\Onboarding\YoureLiveData;
use App\Enums\PermissionEnum;
use App\Enums\PropertyStatus;
use App\Enums\TemporaryPasswordExportFormat;
use App\Models\BusinessDate;
use App\Models\Property;
use App\Models\User;
use App\Services\Staff\PendingSignInUsers;

/**
 * The numbers of the You're live card: the staff who existed at go-live and still hold an unexpired temp password,
 * how many have not been shared yet, and when the passwords expire. Reads only. Null for users without staff.manage
 * and for a property that is not Live.
 */
class YoureLiveSummary
{
    public function __construct(private PendingSignInUsers $pendingSignInUsers) {}

    public function forProperty(Property $property, User $user): ?YoureLiveData
    {
        if ($property->status !== PropertyStatus::Live || ! $user->can(PermissionEnum::StaffManage->value)) {
            return null;
        }

        $pending = $this->pendingSignInUsers->query($property)
            ->where('users.created_at', '<=', $property->went_live_at)
            ->where('temporary_password_expires_at', '>', now())
            ->get(['id', 'temporary_password_expires_at', 'temporary_password_shared_at']);

        $firstBusinessDate = BusinessDate::query()
            ->where('property_id', $property->id)
            ->orderBy('date')
            ->first(['date']);

        $latestExpiry = $pending->max('temporary_password_expires_at');

        return new YoureLiveData(
            propertyName: $property->name,
            firstBusinessDate: $firstBusinessDate?->date->toDateString(),
            pendingCount: $pending->count(),
            notSharedCount: $pending->whereNull('temporary_password_shared_at')->count(),
            expiresOn: $latestExpiry?->toDateString(),
            userIds: $pending->modelKeys(),
            exportFormats: array_values(array_filter(
                TemporaryPasswordExportFormat::options(),
                fn (array $format): bool => in_array($format['value'], [TemporaryPasswordExportFormat::Csv->value, TemporaryPasswordExportFormat::Pdf->value], true),
            )),
        );
    }
}
