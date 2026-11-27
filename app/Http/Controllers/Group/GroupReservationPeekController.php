<?php

namespace App\Http\Controllers\Group;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureGroupAccess;
use App\Models\Property;
use App\Models\PropertyGroup;
use App\Services\Group\GroupReservationPeek;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GroupReservationPeekController extends Controller
{
    /**
     * One reservation for the group side panel, as JSON. A property outside the group, a role without
     * `reservations.view` there, or another property's reservation is a 404.
     * See docs/03-staff/05-group-mode.md#reservation-panel.
     */
    public function __invoke(Request $request, PropertyGroup $propertyGroup, string $propertyCode, int $reservation, GroupReservationPeek $peek): JsonResponse
    {
        /** @var Collection<int, Property> $properties */
        $properties = $request->attributes->get(EnsureGroupAccess::PROPERTIES);
        $data = $peek->for($request->user(), $properties, $propertyCode, $reservation);

        abort_if($data === null, 404);

        return response()->json($data);
    }
}
