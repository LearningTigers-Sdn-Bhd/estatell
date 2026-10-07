<?php

namespace App\Http\Controllers\Property;

use App\Actions\Property\RemovePropertyLogo;
use App\Actions\Property\UploadPropertyLogo;
use App\Http\Controllers\Controller;
use App\Http\Requests\Property\UploadPropertyLogoRequest;
use App\Models\Property;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The property logo, part of the property profile. It lives on the private media disk, so the browser reads it
 * through `show`. See docs/02-property/01-properties.md#logo.
 */
class PropertyLogoController extends Controller
{
    public function show(Request $request, Property $property): StreamedResponse
    {
        $media = $property->getFirstMedia(Property::LOGO) ?? abort(404);

        return $media->toInlineResponse($request);
    }

    public function store(UploadPropertyLogoRequest $request, Property $property, UploadPropertyLogo $uploadPropertyLogo): RedirectResponse
    {
        $uploadPropertyLogo($request->user(), $property, $request->file('logo'));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Logo uploaded.')]);

        return back();
    }

    public function destroy(Request $request, Property $property, RemovePropertyLogo $removePropertyLogo): RedirectResponse
    {
        if ($removePropertyLogo($request->user(), $property)) {
            Inertia::flash('toast', ['type' => 'success', 'message' => __('Logo removed.')]);
        }

        return back();
    }
}
