<?php

namespace App\Actions\Onboarding;

use App\Actions\Property\GenerateDocumentPrefix;
use App\Actions\Property\IssuePropertyCode;
use App\Actions\Property\SeedPropertyDefaults;
use App\Actions\Staff\AssignOwnerRole;
use App\Data\Onboarding\NewOwnerPropertyData;
use App\Enums\OwnerSetupMethod;
use App\Enums\PropertyStatus;
use App\Models\Partner;
use App\Models\Property;
use App\Models\User;
use App\Notifications\OwnerSetupNotification;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreatePropertyForOwner
{
    public function __construct(
        private SeedPropertyDefaults $seedPropertyDefaults,
        private GenerateDocumentPrefix $generateDocumentPrefix,
        private IssuePropertyCode $issuePropertyCode,
        private AssignOwnerRole $assignOwnerRole,
    ) {}

    /**
     * Create an onboarding property and its owner atomically, then send the selected account setup message.
     * With a partner, the property gets the partner's code prefix and is linked to the partner.
     */
    public function __invoke(User $admin, NewOwnerPropertyData $data, ?Partner $partner = null): Property
    {
        $type = $data->type;
        $setupMethod = $data->setupMethod;
        $verifyOwnerEmail = $setupMethod === OwnerSetupMethod::InitialPassword
            && $data->verifyOwnerEmail;

        [$property, $owner] = DB::transaction(function () use ($admin, $data, $partner, $setupMethod, $type, $verifyOwnerEmail): array {
            $property = (new Property)->forceFill([
                'code' => ($this->issuePropertyCode)($partner->code_prefix ?? 'ES'),
                'partner_id' => $partner?->id,
                'name' => $data->propertyName,
                'type' => $type,
                'status' => PropertyStatus::Onboarding,
                'country_code' => 'MY',
                'onboarding_started_at' => now(),
            ]);
            $property->save();

            ($this->generateDocumentPrefix)($property);

            $owner = $property->users()->create([
                'name' => $data->ownerName,
                'email' => Str::lower(trim($data->ownerEmail)),
                'password' => $setupMethod === OwnerSetupMethod::InitialPassword
                    ? $data->password
                    : Str::random(64),
            ]);

            if ($verifyOwnerEmail) {
                $owner->markEmailAsVerified();
            }

            ($this->seedPropertyDefaults)($property, $type, 'MY');
            ($this->assignOwnerRole)($property, $owner);

            activity()
                ->performedOn($property)
                ->causedBy($admin)
                ->event('property.created')
                ->withProperties([
                    'type' => $type->value,
                    'country' => 'MY',
                    'owner_email' => $owner->email,
                    'owner_email_verified' => $verifyOwnerEmail,
                    'setup_method' => $setupMethod->value,
                ])
                ->log('Property created');

            if ($verifyOwnerEmail) {
                activity()
                    ->performedOn($owner)
                    ->causedBy($admin)
                    ->event('owner.email-verified')
                    ->withProperties(['property_id' => $property->id, 'email' => $owner->email])
                    ->log('Owner email verified');
            }

            return [$property, $owner];
        });

        if ($setupMethod === OwnerSetupMethod::EmailLink) {
            $owner->notify(new OwnerSetupNotification);
        } elseif ($verifyOwnerEmail) {
            event(new Verified($owner));
        } else {
            event(new Registered($owner));
        }

        return $property->refresh();
    }
}
