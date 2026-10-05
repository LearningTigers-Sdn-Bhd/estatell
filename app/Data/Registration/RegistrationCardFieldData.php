<?php

namespace App\Data\Registration;

use App\Enums\FieldVisibility;
use App\Models\Property;
use App\Models\RegistrationCardFieldSetting;
use App\Services\Registration\RegistrationCardFieldRules;
use Spatie\LaravelData\Data;

/** One row of the registration card Layout table. */
class RegistrationCardFieldData extends Data
{
    public function __construct(
        public string $field,
        public string $label,
        public string $group,
        public string $groupLabel,
        public string $visibility,
        public bool $displayOnly,
        public bool $canHide,
        public bool $canRequire,
        public ?string $note,
    ) {}

    public static function fromModel(RegistrationCardFieldSetting $setting, Property $property, RegistrationCardFieldRules $rules): self
    {
        $field = $setting->field;
        $allowed = $rules->allowedFor($property, $field);

        return new self(
            field: $field->value,
            label: $field->label(),
            group: $field->group()->value,
            groupLabel: $field->group()->label(),
            visibility: $setting->visibility->value,
            displayOnly: $field->isDisplayOnly(),
            canHide: in_array(FieldVisibility::Hidden, $allowed, true),
            canRequire: ! $field->isDisplayOnly()
                && in_array(FieldVisibility::Required, $allowed, true)
                && in_array(FieldVisibility::Optional, $allowed, true),
            note: $rules->noteFor($property, $field),
        );
    }
}
