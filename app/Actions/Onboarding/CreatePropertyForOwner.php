<?php

namespace App\Actions\Onboarding;

use App\Actions\Property\GenerateDocumentPrefix;
use App\Actions\Property\IssuePropertyCode;
use App\Actions\Property\SeedPropertyDefaults;
use App\Actions\Staff\AssignOwnerRole;
use App\Enums\OwnerSetupMethod;
use App\Enums\PropertyStatus;
use App\Enums\PropertyType;
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
     *
     * @param  array{property_name: string, type: string, owner_name: string, owner_email: string, setup_method: string, password?: string, verify_owner_email?: bool}  $input
     */
    public function __invoke(User $admin, array $input): Property
    {
        $type = PropertyType::from($input['type']);
        $setupMethod = OwnerSetupMethod::from($input['setup_method']);
        $verifyOwnerEmail = $setupMethod === OwnerSetupMethod::InitialPassword
            && ($input['verify_owner_email'] ?? false);

        [$property, $owner] = DB::transaction(function () use ($admin, $input, $setupMethod, $type, $verifyOwnerEmail): array {
            $property = (new Property)->forceFill([
                'code' => ($this->issuePropertyCode)(),
                'name' => $input['property_name'],
                'type' => $type,
                'status' => PropertyStatus::Onboarding,
                'country_code' => 'MY',
                'onboarding_started_at' => now(),
            ]);
            $property->save();

            ($this->generateDocumentPrefix)($property);

            $owner = $property->users()->create([
                'name' => $input['owner_name'],
                'email' => Str::lower(trim($input['owner_email'])),
                'password' => $setupMethod === OwnerSetupMethod::InitialPassword
                    ? $input['password']
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
