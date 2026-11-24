<?php

namespace App\Data\AiAssistant;

use App\Enums\AiScope;
use App\Enums\AiScopeKind;
use App\Models\Property;
use App\Services\AiAssistant\AiKeyProvider;
use App\Services\AiAssistant\AiScopes;
use Spatie\LaravelData\Data;

/**
 * The Places or the Capabilities tab of Settings › AI assistant: the hotel's switch for each scope of that kind the
 * admin allows, and whether the hotel has saved its OpenAI key (without one, every place is off).
 */
class AiAssistantSettingsData extends Data
{
    /**
     * @param  list<AiScopeData>  $scopes
     */
    public function __construct(
        public array $scopes,
        public bool $hasKey,
    ) {}

    public static function forProperty(Property $property, AiScopeKind $kind): self
    {
        $aiScopes = app(AiScopes::class);
        $rows = $aiScopes->rows($property);
        $visible = array_values(array_filter($aiScopes->forHotel($property), fn (AiScope $scope): bool => $scope->kind() === $kind));

        return new self(
            scopes: array_map(
                function (AiScope $scope) use ($rows): AiScopeData {
                    $place = $scope->requiredScope();
                    $placeRow = $place === null ? null : $rows->get($place->value);

                    return AiScopeData::forHotel($scope, $rows[$scope->value], $place === null || ($placeRow !== null && $placeRow->allowed && $placeRow->enabled));
                },
                $visible,
            ),
            hasKey: app(AiKeyProvider::class)->hasKey($property),
        );
    }
}
