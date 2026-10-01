<?php

namespace App\Http\Controllers\NightAudit;

use App\Data\NightAudit\BusinessDateData;
use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Services\NightAudit\CurrentBusinessDate;
use Illuminate\Http\JsonResponse;

class BusinessDateController extends Controller
{
    /**
     * The current business date as JSON, or null while the property is not live.
     */
    public function show(Property $property, CurrentBusinessDate $currentBusinessDate): JsonResponse
    {
        $businessDate = $currentBusinessDate->get($property);

        if ($businessDate === null) {
            return new JsonResponse('null', json: true);
        }

        return response()->json(BusinessDateData::fromBusinessDate($businessDate, $property));
    }
}
