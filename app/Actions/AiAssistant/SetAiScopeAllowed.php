<?php

namespace App\Actions\AiAssistant;

use App\Enums\AiScope;
use App\Features\AiAssistant;
use App\Models\Property;
use App\Models\User;
use App\Services\AiAssistant\AiScopes;
use Laravel\Pennant\Feature;

/**
 * A platform admin allows or stops one AI assistant scope for a property. Stopping keeps the hotel's own switch, so
 * allowing the scope again brings back the hotel's choice. Returns false, writing nothing, while the AI assistant feature
 * is off, while the scope's experimental place is not released, or when nothing changes. See docs/34-ai-assistant/01-overview.md#scopes.
 */
class SetAiScopeAllowed
{
    public function __construct(private AiScopes $aiScopes) {}

    public function __invoke(User $admin, Property $property, AiScope $scope, bool $allowed): bool
    {
        if (! Feature::for($property)->active(AiAssistant::class) || ! $this->aiScopes->isReleased($scope)) {
            return false;
        }

        $row = $property->aiScopes()->firstOrNew(['scope' => $scope]);

        if ($row->exists && $row->allowed === $allowed) {
            return false;
        }

        if (! $row->exists && ! $allowed) {
            return false;
        }

        $row->allowed = $allowed;
        $row->save();

        activity()
            ->performedOn($property)
            ->causedBy($admin)
            ->event($allowed ? 'ai-assistant.scope-allowed' : 'ai-assistant.scope-stopped')
            ->withProperties(['property_id' => $property->id, 'scope' => $scope->value])
            ->log($allowed ? "AI assistant: {$scope->label()} allowed" : "AI assistant: {$scope->label()} stopped");

        return true;
    }
}
