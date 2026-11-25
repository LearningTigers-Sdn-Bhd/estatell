<?php

namespace App\Services\AiAssistant;

use App\Enums\AiScope;
use App\Features\AiAssistant;
use App\Models\Property;
use App\Models\PropertyAiScope;
use Illuminate\Support\Collection;
use Laravel\Pennant\Feature;

/**
 * Answers which AI assistant scopes a property may use. A scope is on when the AI assistant feature is on, the feature
 * the scope needs is on, the place it needs is on, and its row is both allowed (admin) and enabled (hotel).
 * See docs/34-ai-assistant/01-overview.md#scopes.
 */
class AiScopes
{
    public function isOn(Property $property, AiScope $scope): bool
    {
        if (! Feature::for($property)->active(AiAssistant::class)) {
            return false;
        }

        return $this->isOnWith($property, $scope, $this->rows($property));
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
     * The scopes the hotel sees in Settings: allowed by the admin, with the feature they need on.
     *
     * @return list<AiScope>
     */
    public function forHotel(Property $property): array
    {
        $rows = $this->rows($property);

        return array_values(array_filter(
            AiScope::cases(),
            fn (AiScope $scope): bool => ($rows->get($scope->value)->allowed ?? false) && $this->hasRequiredFeature($property, $scope),
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

        if ($row === null || ! $row->allowed || ! $row->enabled || ! $this->hasRequiredFeature($property, $scope)) {
            return false;
        }

        $place = $scope->requiredScope();

        return $place === null || $this->isOnWith($property, $place, $rows);
    }
}
