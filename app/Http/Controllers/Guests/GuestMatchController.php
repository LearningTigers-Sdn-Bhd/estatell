<?php

namespace App\Http\Controllers\Guests;

use App\Data\Guests\GuestMatchData;
use App\Enums\PermissionEnum;
use App\Http\Controllers\Controller;
use App\Http\Requests\Guests\GuestMatchRequest;
use App\Models\Property;
use App\Services\Guests\GuestMatcher;
use Illuminate\Http\JsonResponse;

class GuestMatchController extends Controller
{
    /**
     * Possible duplicates of the details typed so far, for the duplicate warning on the forms.
     */
    public function __invoke(GuestMatchRequest $request, Property $property, GuestMatcher $guestMatcher): JsonResponse
    {
        $matches = $guestMatcher->find(
            property: $property,
            name: $request->validated('name'),
            email: $request->validated('email'),
            phone: $request->validated('phone'),
            dateOfBirth: $request->validated('date_of_birth'),
            idNumbers: $request->validated('id_numbers') ?? [],
            includeIdNumbers: $request->user()->can(PermissionEnum::GuestsViewDocuments->value),
            excludeGuestId: $request->validated('exclude_guest_id'),
        );

        return response()->json(['matches' => array_map(fn (array $match): GuestMatchData => GuestMatchData::fromMatch($match), $matches)]);
    }
}
