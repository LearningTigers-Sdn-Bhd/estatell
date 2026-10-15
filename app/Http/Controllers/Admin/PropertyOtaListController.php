<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Platform\MarkOtaCredentialProcessed;
use App\Actions\Platform\RevealOtaCredential;
use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Services\Platform\PropertyOtaLists;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PropertyOtaListController extends Controller
{
    /**
     * Return one saved password as JSON. The password never goes in page props, and each reveal is logged.
     */
    public function reveal(Request $request, Property $property, int $credential, PropertyOtaLists $propertyOtaLists, RevealOtaCredential $revealOtaCredential): JsonResponse
    {
        $row = $propertyOtaLists->credentialFor($property, $credential);

        return response()->json(['password' => $revealOtaCredential($request->user(), $row)]);
    }

    /**
     * Mark one pending OTA list as connected by the team.
     */
    public function markProcessed(Request $request, Property $property, int $credential, PropertyOtaLists $propertyOtaLists, MarkOtaCredentialProcessed $markOtaCredentialProcessed): RedirectResponse
    {
        $markOtaCredentialProcessed($request->user(), $propertyOtaLists->credentialFor($property, $credential));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Marked processed.')]);

        return back(fallback: route('admin.properties.ota-lists', $property));
    }
}
