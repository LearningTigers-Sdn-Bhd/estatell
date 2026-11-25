<?php

namespace App\Services\AiAssistant;

use App\Enums\AiScope;
use App\Features\AiAssistant;
use App\Models\Property;
use App\Models\PropertyAiScope;
use App\Services\Platform\PlatformSettings;
use Illuminate\Support\Collection;
use Laravel\Pennant\Feature;

/**
 * Answers which AI assistant scopes a property may use. A scope is on when the AI assistant feature is on, the feature
 * the scope needs is on, the place it needs is on, its row is both allowed (admin) and enabled (hotel, except for an
 * admin-only scope), and the hotel
 * has saved its own OpenAI key. An experimental place (and its capabilities) also needs its platform switch on.
 * See docs/35-ai-assistant/01-overview.md#scopes.
 */
class AiScopes
{
    public function __construct(
        private readonly AiKeyProvider $keys,
        private readonly PlatformSettings $platformSettings,
    ) {}

    public function isOn(Property $property, AiScope $scope): bool
    {
        if (! Feature::for($property)->active(AiAssistant::class) || ! $this->keys->hasKey($property)) {
            return false;
        }

        return $this->isOnWith($property, $scope, $this->rows($property));
    }

    /**
     * Whether the platform admin allows a scope for a property: the AI assistant feature is on, the scope is released,
     * its feature is on and its row is allowed. The hotel's switch and key do not count. The admin Chat API tab shows
     * while this is true for `Api`.
     */
    public function isAllowed(Property $property, AiScope $scope): bool
    {
        return Feature::for($property)->active(AiAssistant::class)
            && ($this->rows($property)->get($scope->value)->allowed ?? false)
            && $this->isReleased($scope)
            && $this->hasRequiredFeature($property, $scope);
    }

    /**
     * Whether the feature a scope needs (Concierge, Booking engine) is on for the property.
     */
    public function hasRequiredFeature(Property $property, AiScope $scope): bool
    {
        $feature = $scope->requiredFeature();

        return $feature === null || Feature::for($property)->active($feature->featureClass());
    }

    /**
     * Whether the platform admin has turned on the experimental place a scope belongs to. Scopes outside the
     * experimental places are always released. See docs/35-ai-assistant/01-overview.md#experimental-places.
     */
    public function isReleased(AiScope $scope): bool
    {
        $settings = $this->platformSettings->current();

        return match ($scope->requiredScope() ?? $scope) {
            AiScope::BookingEngine => $settings->ai_booking_website_enabled,
            AiScope::Staff => $settings->ai_staff_helper_enabled,
            default => true,
        };
    }

    /**
     * The scopes the admin sees on the property's Features tab: every released scope.
     *
     * @return list<AiScope>
     */
    public function released(): array
    {
        return array_values(array_filter(AiScope::cases(), fn (AiScope $scope): bool => $this->isReleased($scope)));
    }

    /**
     * The scopes the hotel sees in Settings: released, allowed by the admin, with the feature they need on. An admin-only
     * scope is never one of them.
     *
     * @return list<AiScope>
     */
    public function forHotel(Property $property): array
    {
        $rows = $this->rows($property);

        return array_values(array_filter(
            $this->released(),
            fn (AiScope $scope): bool => ! $scope->isAdminOnly() && ($rows->get($scope->value)->allowed ?? false) && $this->hasRequiredFeature($property, $scope),
        ));
    }

    /**
     * The property's scope rows, keyed by the scope value. A scope with no row is off on both layers.
     *
     * @return Collection<string, PropertyAiScope>
     */
    public function rows(Property $property): Collection
    {
        return $property->aiScopes()->get()->keyBy(fn (PropertyAiScope $row): string => $row->scope->value);
    }

    /**
     * @param  Collection<string, PropertyAiScope>  $rows
     */
    private function isOnWith(Property $property, AiScope $scope, Collection $rows): bool
    {
        $row = $rows->get($scope->value);

        if ($row === null || ! $row->allowed || (! $row->enabled && ! $scope->isAdminOnly()) || ! $this->isReleased($scope) || ! $this->hasRequiredFeature($property, $scope)) {
            return false;
        }

        $place = $scope->requiredScope();

        return $place === null || $this->isOnWith($property, $place, $rows);
    }
}
