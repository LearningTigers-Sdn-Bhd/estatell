<?php

namespace App\Actions\Concierge;

use App\Models\Property;
use App\Models\User;

class UpdateConciergeSettings
{
    public const array POLICY_FIELDS = ['room_policy', 'payment_policy', 'other_policies'];

    /**
     * Save how the Concierge page of a property behaves: whether a guest with no booking may book a room from the lobby
     * page, whether a guest may ask for a refund after check-out, and the house rules and other policy texts shown in the
     * property guide. A policy text missing from `$policyTexts` is left as it is; a blank one is cleared. The settings row is created for a property that has none. Nothing is written or logged when nothing changed.
     *
     * @param  array<string, mixed>  $policyTexts  Validated `POLICY_FIELDS`, only those the request sent.
     */
    public function __invoke(User $actor, Property $property, bool $walkInEnabled, bool $refundRequestsEnabled, ?string $houseRules, array $policyTexts = [], ?bool $locationCheckEnabled = null): void
    {
        $settings = $property->settings()->firstOrNew();
        $settings->fill([
            'concierge_walk_in_enabled' => $walkInEnabled,
            'concierge_refund_requests_enabled' => $refundRequestsEnabled,
            'house_rules' => filled($houseRules) ? trim((string) $houseRules) : null,
        ]);
        if ($locationCheckEnabled !== null) {
            $settings->concierge_location_check_enabled = $locationCheckEnabled;
        }

        foreach (self::POLICY_FIELDS as $field) {
            if (array_key_exists($field, $policyTexts)) {
                $settings->{$field} = filled($policyTexts[$field]) ? trim((string) $policyTexts[$field]) : null;
            }
        }

        $changed = array_keys($settings->getDirty());

        if ($changed === []) {
            return;
        }

        $settings->save();

        activity()
            ->performedOn($property)
            ->causedBy($actor)
            ->event('concierge.settings-updated')
            ->withProperties(['property_id' => $property->id, 'changed' => $changed])
            ->log('Concierge settings updated');
    }
}
