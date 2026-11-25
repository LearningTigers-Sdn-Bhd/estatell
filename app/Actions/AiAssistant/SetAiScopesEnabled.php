<?php

namespace App\Actions\AiAssistant;

use App\Enums\AiScope;
use App\Models\Property;
use App\Models\User;
use App\Services\AiAssistant\AiScopes;
use Illuminate\Support\Facades\DB;

/**
 * The hotel turns its allowed AI assistant scopes on or off. Returns false, writing nothing, when a scope in the list is
 * not one the hotel may set (the admin stopped it, or its feature is off). Nothing is logged when nothing changes.
 * See docs/35-ai-assistant/01-overview.md#scopes.
 */
class SetAiScopesEnabled
{
    public function __construct(private AiScopes $aiScopes) {}

    /**
     * @param  array<string, bool>  $enabled  Scope value to its switch.
     */
    public function __invoke(User $actor, Property $property, array $enabled): bool
    {
        $allowed = array_map(fn (AiScope $scope): string => $scope->value, $this->aiScopes->forHotel($property));

        if (array_diff(array_keys($enabled), $allowed) !== []) {
            return false;
        }

        $changed = DB::transaction(function () use ($property, $enabled): array {
            $rows = $this->aiScopes->rows($property);
            $changed = [];

            foreach ($enabled as $value => $on) {
                $row = $rows->get($value);

                if ($row !== null && $row->enabled !== $on) {
                    $row->enabled = $on;
                    $row->save();
                    $changed[] = $value;
                }
            }

            return $changed;
        });

        if ($changed !== []) {
            activity()
                ->performedOn($property)
                ->causedBy($actor)
                ->event('ai-assistant.scopes-updated')
                ->withProperties(['property_id' => $property->id, 'changed' => $changed])
                ->log('AI assistant switches updated');
        }

        return true;
    }
}
