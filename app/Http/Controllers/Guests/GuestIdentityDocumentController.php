<?php

namespace App\Http\Controllers\Guests;

use App\Actions\Guests\AddIdentityDocument;
use App\Actions\Guests\RecordIdentityDocumentAccess;
use App\Actions\Guests\RemoveIdentityDocument;
use App\Actions\Guests\UpdateIdentityDocument;
use App\Http\Controllers\Controller;
use App\Http\Requests\Guests\IdentityDocumentRequest;
use App\Models\Guest;
use App\Models\GuestIdentityDocument;
use App\Models\Property;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class GuestIdentityDocumentController extends Controller
{
    /**
     * How long a scan link works, in minutes.
     */
    private const int SCAN_LINK_MINUTES = 5;

    public function store(IdentityDocumentRequest $request, Property $property, Guest $guest, AddIdentityDocument $addIdentityDocument): RedirectResponse
    {
        $document = $addIdentityDocument($request->user(), $guest, $request->documentAttributes(), $request->frontScan(), $request->backScan());

        Inertia::flash('toast', $document !== null
            ? ['type' => 'success', 'message' => __('ID document added.')]
            : ['type' => 'error', 'message' => __(':name cannot be edited.', ['name' => $guest->full_name])]);

        return to_route('guests.documents.index', ['property' => $property, 'guest' => $guest]);
    }

    public function update(IdentityDocumentRequest $request, Property $property, Guest $guest, GuestIdentityDocument $document, UpdateIdentityDocument $updateIdentityDocument): RedirectResponse
    {
        Inertia::flash('toast', $updateIdentityDocument($request->user(), $document, $request->documentAttributes(), $request->frontScan(), $request->backScan(), $request->removeScans())
            ? ['type' => 'success', 'message' => __('ID document saved.')]
            : ['type' => 'error', 'message' => __(':name cannot be edited.', ['name' => $guest->full_name])]);

        return to_route('guests.documents.index', ['property' => $property, 'guest' => $guest]);
    }

    public function destroy(Request $request, Property $property, Guest $guest, GuestIdentityDocument $document, RemoveIdentityDocument $removeIdentityDocument): RedirectResponse
    {
        $removeIdentityDocument($request->user(), $document);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('ID document removed.')]);

        return to_route('guests.documents.index', ['property' => $property, 'guest' => $guest]);
    }

    /**
     * The full number for the Reveal button and the edit sheet. The request is logged.
     */
    public function reveal(Request $request, Property $property, Guest $guest, GuestIdentityDocument $document, RecordIdentityDocumentAccess $recordAccess): JsonResponse
    {
        $recordAccess($request->user(), $document);

        return response()->json(['number' => $document->formattedNumber()]);
    }

    /**
     * Open a scan through a short-lived link. The request is logged.
     */
    public function scan(Request $request, Property $property, Guest $guest, GuestIdentityDocument $document, string $scan, RecordIdentityDocumentAccess $recordAccess): RedirectResponse
    {
        $collection = match ($scan) {
            'front' => GuestIdentityDocument::FRONT_SCAN,
            'back' => GuestIdentityDocument::BACK_SCAN,
            default => abort(404),
        };
        $media = $document->getFirstMedia($collection) ?? abort(404);

        $recordAccess($request->user(), $document, $collection);

        return redirect()->away($media->getTemporaryUrl(now()->addMinutes(self::SCAN_LINK_MINUTES)));
    }
}
