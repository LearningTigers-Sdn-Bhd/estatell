<?php

namespace App\Actions\Onboarding;

use App\Actions\Property\GenerateDocumentPrefix;
use App\Actions\Property\IssuePropertyCode;
use App\Actions\Staff\AssignOwnerRole;
use App\Actions\Staff\SeedDefaultRoles;
use App\Enums\PropertyStatus;
use App\Models\Partner;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RegisterPropertyOwner
{
    public function __construct(
        private GenerateDocumentPrefix $generateDocumentPrefix,
        private IssuePropertyCode $issuePropertyCode,
        private SeedDefaultRoles $seedDefaultRoles,
        private AssignOwnerRole $assignOwnerRole,
    ) {}

    /**
     * Create a property in onboarding and its owner in one transaction, so every staff user has a property.
     * With a partner (a signup link), the property gets the partner's code prefix and is linked to the partner.
     *
     * The default roles are seeded here and the owner gets the Owner role from the first login.
     * Type and country stay empty until the Basics wizard step, which seeds the other property defaults.
     *
     * @param  array{name: string, email: string, password: string, property_name: string}  $input
     */
    public function __invoke(array $input, ?Partner $partner = null): User
    {
        return DB::transaction(function () use ($input, $partner): User {
            $property = (new Property)->forceFill([
                'code' => ($this->issuePropertyCode)($partner?->code_prefix ?? 'ES'),
                'partner_id' => $partner?->id,
                'name' => $input['property_name'],
                'status' => PropertyStatus::Onboarding,
                'onboarding_started_at' => now(),
            ]);
            $property->save();

            ($this->generateDocumentPrefix)($property);

            $owner = $property->users()->create([
                'name' => $input['name'],
                'email' => $input['email'],
                'password' => $input['password'],
            ]);

            ($this->seedDefaultRoles)($property);
            ($this->assignOwnerRole)($property, $owner);

            return $owner;
        });
    }
}
