<?php

use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Property;
use App\Models\User;

/**
 * Link a company user to a new company at the property. The link is Active unless `$state` is `pending` or `revoked`.
 *
 * @return array{0: Company, 1: CompanyUser}
 */
function portalLinkFor(User $user, Property $property, string $state = 'active', string $companyName = 'Borneo Travel'): array
{
    $company = Company::factory()->for($property)->create(['name' => $companyName]);
    $factory = CompanyUser::factory()->for($company);
    $factory = match ($state) {
        'pending' => $factory->pending(),
        'revoked' => $factory->revoked(),
        default => $factory,
    };
    $link = $factory->create(['user_id' => $user->id]);

    return [$company, $link];
}

function portalUser(): User
{
    return User::factory()->companyUser()->create();
}
