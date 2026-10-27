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
     * The same checks for the replay, which runs after go-live: the property must be Live and the bundle must be its own.
     *
     * @throws InvalidArgumentException with a sentence for the console when the replay must not run
     */
    public static function resolveForReplay(string $bundlePath, string $propertyCode, string $actorEmail): self
    {
        $target = self::load($bundlePath, $propertyCode, $actorEmail);

        if ($target->property->status !== PropertyStatus::Live) {
            throw new InvalidArgumentException('The property is not live. Approve go-live with the opening date before the replay.');
        }

        return $target;
    }

    /**
     * `$alignPrefix` is for `wastays:import-setup`, which replaces a different document prefix with the bundle's hotel code.
     *
     * @throws InvalidArgumentException with a sentence for the console when the import must not run
     */
    public static function resolve(string $bundlePath, string $propertyCode, string $actorEmail, bool $alignPrefix = false): self
    {
        $target = self::load($bundlePath, $propertyCode, $actorEmail, $alignPrefix);

        if (! in_array($target->property->status, [PropertyStatus::Onboarding, PropertyStatus::PendingApproval], true)) {
            throw new InvalidArgumentException('The property has already gone live. Import before go-live is approved.');
        }

        return $target;
    }

    private static function load(string $bundlePath, string $propertyCode, string $actorEmail, bool $alignPrefix = false): self
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

        if (! $alignPrefix && $property->document_prefix !== $bundle->manifest->hotelCode) {
            throw new InvalidArgumentException("The bundle is for {$bundle->manifest->hotelCode}, but the property's document prefix is ".($property->document_prefix ?? 'not set').'.');
        }

        return new self($bundle, $property, $actor);
    }
}
