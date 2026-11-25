<?php

namespace App\Data\AiAssistant;

use App\Enums\AiScope;
use App\Enums\AiScopeKind;
use App\Models\PropertyAiScope;
use Spatie\LaravelData\Data;

/**
 * One AI assistant scope with its two switches. `$note` says what else it needs: the feature (admin) or the place
 * (hotel). Null when nothing else is needed.
 */
class AiScopeData extends Data
{
    public function __construct(
        public AiScope $scope,
        public AiScopeKind $kind,
        public string $label,
        public string $description,
        public bool $allowed,
        public bool $enabled,
        public bool $available,
        public ?string $note,
    ) {}

    /**
     * For the admin Features tab. A scope whose feature is off cannot be allowed.
     */
    public static function forAdmin(AiScope $scope, ?PropertyAiScope $row, bool $hasRequiredFeature): self
    {
        $feature = $scope->requiredFeature();
        $place = $scope->requiredScope();

        return new self(
            scope: $scope,
            kind: $scope->kind(),
            label: $scope->label(),
            description: $scope->description(),
            allowed: $row->allowed ?? false,
            enabled: $row->enabled ?? false,
            available: $hasRequiredFeature,
            note: match (true) {
                ! $hasRequiredFeature && $feature !== null => __('Needs the :feature feature.', ['feature' => $feature->label()]),
                $place !== null => __('Works with :place.', ['place' => $place->label()]),
                default => null,
            },
        );
    }

    /**
     * For Settings › AI assistant. A capability whose place is off shows a note but can still be turned on.
     */
    public static function forHotel(AiScope $scope, PropertyAiScope $row, bool $placeIsEnabled): self
    {
        $place = $scope->requiredScope();

        return new self(
            scope: $scope,
            kind: $scope->kind(),
            label: $scope->label(),
            description: $scope->description(),
            allowed: $row->allowed,
            enabled: $row->enabled,
            available: true,
            note: $place !== null && ! $placeIsEnabled ? __('Works only while :place is on.', ['place' => $place->label()]) : null,
        );
    }
}
