<?php

namespace App\Data\Onboarding;

use Spatie\LaravelData\Data;

/**
 * The You're live card: the staff who still hold a temp password from go-live, and the sign-in options for them.
 */
class YoureLiveData extends Data
{
    /**
     * @param  list<int>  $userIds  every pending staff user, for the copy message and the export
     * @param  list<array{value: string, label: string}>  $exportFormats
     */
    public function __construct(
        public string $propertyName,
        public ?string $firstBusinessDate,
        public int $pendingCount,
        public int $notSharedCount,
        public ?string $expiresOn,
        public array $userIds,
        public array $exportFormats,
        public int $migrationWindowDays,
    ) {}
}
