<?php

namespace App\Services\Onboarding\Wastays;

use App\Enums\PropertyStatus;
use App\Models\Property;
use App\Models\User;
use InvalidArgumentException;

/**
 * The bundle, property and admin an import command writes with. Shared by `wastays:import-setup` and
 * `wastays:import-guests`, so both refuse the same way before they write anything.
 */
final readonly class WastaysImportTarget
{
    private function __construct(
        public WastaysBundle $bundle,
        public Property $property,
        public User $actor,
    ) {}

    /**
     * @throws InvalidArgumentException with a sentence for the console when the import must not run
     */
    public static function resolve(string $bundlePath, string $propertyCode, string $actorEmail): self
    {
        $bundle = WastaysBundle::open($bundlePath);

        $property = Property::query()->where('code', $propertyCode)->first();
        if ($property === null) {
            throw new InvalidArgumentException("No property has the code {$propertyCode}.");
        }

        $actor = User::query()->where('email', $actorEmail)->first();
        if ($actor === null || ! $actor->is_admin) {
            throw new InvalidArgumentException("No admin user has the email {$actorEmail}.");
        }

        if ($property->document_prefix !== $bundle->manifest->hotelCode) {
            throw new InvalidArgumentException("The bundle is for {$bundle->manifest->hotelCode}, but the property's document prefix is ".($property->document_prefix ?? 'not set').'.');
        }

        if (! in_array($property->status, [PropertyStatus::Onboarding, PropertyStatus::PendingApproval], true)) {
            throw new InvalidArgumentException('The property has already gone live. Import before go-live is approved.');
        }

        return new self($bundle, $property, $actor);
    }
}
