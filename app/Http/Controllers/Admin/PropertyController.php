<?php

namespace App\Http\Controllers\Admin;

use App\Data\Platform\AdminPropertyData;
use App\Data\Platform\AdminPropertyRowData;
use App\Data\Platform\PropertyFeatureData;
use App\Enums\PropertyStatus;
use App\Enums\PropertyType;
use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Services\Platform\PropertyDirectory;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\LaravelData\PaginatedDataCollection;

class PropertyController extends Controller
{
    /**
     * List every property with search, filters and sorting in the URL.
     */
    public function index(Request $request, PropertyDirectory $propertyDirectory): Response
    {
        return Inertia::render('admin/properties/index', [
            'properties' => AdminPropertyRowData::collect($propertyDirectory->paginate($request), PaginatedDataCollection::class),
            'filters' => [
                'search' => $request->string('filter.search')->toString(),
                'status' => $request->string('filter.status')->toString(),
                'type' => $request->string('filter.type')->toString(),
            ],
            'sort' => $request->string('sort', '-code')->toString(),
            'statuses' => PropertyStatus::options(),
            'types' => PropertyType::options(),
        ]);
    }

    /**
     * Show one property with its staff.
     */
    public function show(Property $property): Response
    {
        $property->load(['users' => fn ($query) => $query->orderBy('name')]);

        return Inertia::render('admin/properties/show', [
            'property' => AdminPropertyData::fromModel($property),
            'features' => PropertyFeatureData::forProperty($property),
        ]);
    }
}
