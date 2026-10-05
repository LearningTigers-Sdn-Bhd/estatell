<?php

namespace App\Actions\Registration;

use App\Enums\FieldVisibility;
use App\Enums\RegistrationCardField;
use App\Enums\RegistrationCardFieldGroup;
use App\Models\Property;
use App\Models\RegistrationCardFieldSetting;
use App\Models\User;
use App\Services\Registration\RegistrationCardFieldRules;
use Illuminate\Support\Facades\DB;

class SaveRegistrationCardFields
{
    public function __construct(private RegistrationCardFieldRules $rules) {}

    /**
     * @param  list<RegistrationCardField>  $order
     */
    private function keepsGroupsTogether(array $order): bool
    {
        $groups = array_map(fn (RegistrationCardFieldGroup $group): string => $group->value, RegistrationCardFieldGroup::cases());
        $previous = -1;

        foreach ($order as $field) {
            $current = array_search($field->group()->value, $groups, true);

            if ($current < $previous) {
                return false;
            }

            $previous = $current;
        }

        return true;
    }

    /**
     * Save the visibility and the order of every registration card field.
     *
     * Returns false, without writing, when a value is not allowed for its field right now,
     * for example Nationality hidden while a tax for foreign guests only is enabled,
     * or when the order moves a field out of its group. The groups keep their fixed order.
     * A save that changes nothing is not logged.
     *
     * @param  array<string, FieldVisibility>  $visibilities  keyed by `RegistrationCardField` value, one entry per field
     * @param  list<RegistrationCardField>  $order  every field once, in card order
     */
    public function __invoke(Property $property, User $actor, array $visibilities, array $order): bool
    {
        if (! $this->keepsGroupsTogether($order)) {
            return false;
        }

        return DB::transaction(function () use ($property, $actor, $visibilities, $order): bool {
            $property = Property::query()->lockForUpdate()->findOrFail($property->id);
            $settings = $property->registrationCardFields()->get()->keyBy(fn (RegistrationCardFieldSetting $setting): string => $setting->field->value);
            $positions = array_flip(array_map(fn (RegistrationCardField $field): string => $field->value, $order));

            foreach (RegistrationCardField::cases() as $field) {
                $visibility = $visibilities[$field->value] ?? null;

                if ($visibility === null || ! $settings->has($field->value) || ! in_array($visibility, $this->rules->allowedFor($property, $field), true)) {
                    return false;
                }
            }

            $before = [];
            $after = [];

            foreach ($settings as $key => $setting) {
                if ($setting->visibility !== $visibilities[$key]) {
                    $before[$key] = $setting->visibility->value;
                    $after[$key] = $visibilities[$key]->value;
                    $setting->update(['visibility' => $visibilities[$key]]);
                }
            }

            $orderBefore = $settings->sortBy('position')->map(fn (RegistrationCardFieldSetting $setting): string => $setting->field->value)->values()->all();

            foreach ($settings as $key => $setting) {
                if ($setting->position !== $positions[$key]) {
                    $setting->update(['position' => $positions[$key]]);
                }
            }

            $orderAfter = array_map(fn (RegistrationCardField $field): string => $field->value, $order);

            if ($after === [] && $orderBefore === $orderAfter) {
                return true;
            }

            activity()
                ->performedOn($property)
                ->causedBy($actor)
                ->event('registration_card_fields.updated')
                ->withProperties([
                    'property_id' => $property->id,
                    'before' => $before,
                    'after' => $after,
                    'order' => $orderBefore === $orderAfter ? null : ['before' => $orderBefore, 'after' => $orderAfter],
                ])
                ->log('Registration card fields updated');

            return true;
        });
    }
}
