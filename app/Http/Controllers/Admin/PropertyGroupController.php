<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Platform\AddPropertyToGroup;
use App\Actions\Platform\CreatePropertyGroup;
use App\Actions\Platform\RemovePropertyFromGroup;
use App\Actions\Platform\RenamePropertyGroup;
use App\Data\Platform\PropertyGroupData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PropertyGroupRequest;
use App\Models\Property;
use App\Models\PropertyGroup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin › Property groups: the groups whose staff can be given access to each other's properties, and which
 * properties are in each. See docs/03-staff/04-property-groups.md.
 */
class PropertyGroupController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/property-groups/index', [
            'groups' => PropertyGroup::query()->with('properties')->orderBy('name')->get()
                ->map(fn (PropertyGroup $group): PropertyGroupData => PropertyGroupData::fromModel($group)),
            'ungrouped' => Property::query()->whereNull('property_group_id')->orderBy('name')->get(['code', 'name'])
                ->map(fn (Property $property): array => ['value' => $property->code, 'label' => "{$property->name} ({$property->code})"]),
        ]);
    }

    public function store(PropertyGroupRequest $request, CreatePropertyGroup $createPropertyGroup): RedirectResponse
    {
        $group = $createPropertyGroup($request->user(), $request->string('name')->toString());
        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name was created.', ['name' => $group->name])]);

        return to_route('admin.property-groups.index');
    }

    public function update(PropertyGroupRequest $request, PropertyGroup $propertyGroup, RenamePropertyGroup $renamePropertyGroup): RedirectResponse
    {
        $renamePropertyGroup($request->user(), $propertyGroup, $request->string('name')->toString());
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Group renamed.')]);

        return to_route('admin.property-groups.index');
    }

    public function addProperty(Request $request, PropertyGroup $propertyGroup, AddPropertyToGroup $addPropertyToGroup): RedirectResponse
    {
        $validated = $request->validate(['property' => ['required', 'string', Rule::exists('properties', 'code')]]);
        $property = Property::query()->where('code', $validated['property'])->firstOrFail();

        Inertia::flash('toast', $addPropertyToGroup($request->user(), $propertyGroup, $property)
            ? ['type' => 'success', 'message' => __(':property was added to :group.', ['property' => $property->name, 'group' => $propertyGroup->name])]
            : ['type' => 'error', 'message' => __(':property is already in a group.', ['property' => $property->name])]);

        return to_route('admin.property-groups.index');
    }

    public function removeProperty(Request $request, PropertyGroup $propertyGroup, Property $property, RemovePropertyFromGroup $removePropertyFromGroup): RedirectResponse
    {
        abort_unless($property->property_group_id === $propertyGroup->id, 404);

        Inertia::flash('toast', $removePropertyFromGroup($request->user(), $property)
            ? ['type' => 'success', 'message' => __(':property was removed from :group.', ['property' => $property->name, 'group' => $propertyGroup->name])]
            : ['type' => 'error', 'message' => __('Staff share :property with another property of the group. Deactivate their access in Settings › Staff first.', ['property' => $property->name])]);

        return to_route('admin.property-groups.index');
    }
}
