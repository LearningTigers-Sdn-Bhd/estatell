<?php

namespace App\Http\Controllers\Registration;

use App\Actions\Registration\PrepareRegistrationCard;
use App\Actions\Registration\StoreRegistrationCardPdf;
use App\Enums\Locale;
use App\Enums\RegistrationCardStatus;
use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Models\RegistrationCard;
use App\Models\UnitGuest;
use App\Services\Registration\RegistrationCardDocumentBuilder;
use App\Services\Registration\RegistrationCardPdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Inertia\Inertia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class RegistrationCardPdfController extends Controller
{
    /**
     * Download the signed card as a PDF. The first download renders it, later ones send the stored file.
     */
    public function signed(Property $property, UnitGuest $unitGuest, StoreRegistrationCardPdf $storeRegistrationCardPdf): Media|RedirectResponse
    {
        $this->ensureOwned($property, $unitGuest);

        $card = RegistrationCard::query()
            ->where('unit_guest_id', $unitGuest->id)
            ->where('status', RegistrationCardStatus::Signed->value)
            ->first();
        $media = $card === null ? null : $storeRegistrationCardPdf($card);

        if ($media === null) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('There is no signed card to download.')]);

            return to_route('registration.show', [$property, $unitGuest]);
        }

        return $media;
    }

    /**
     * The draft as a form to print, for a guest who signs on paper. The language is `?locale=`.
     */
    public function blank(Request $request, Property $property, UnitGuest $unitGuest, PrepareRegistrationCard $prepareRegistrationCard, RegistrationCardDocumentBuilder $builder, RegistrationCardPdf $pdf): Response|RedirectResponse
    {
        $this->ensureOwned($property, $unitGuest);

        $card = $prepareRegistrationCard($unitGuest);
        $document = $card?->status === RegistrationCardStatus::Draft
            ? $builder->forBlank($property, $card, Locale::tryFrom((string) $request->query('locale')))
            : null;

        if ($document === null) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('There is no draft card to print.')]);

            return to_route('registration.show', [$property, $unitGuest]);
        }

        return response($pdf->render($document), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="registration-card-blank.pdf"',
        ]);
    }

    private function ensureOwned(Property $property, UnitGuest $unitGuest): void
    {
        abort_unless($unitGuest->reservationUnit()->where('property_id', $property->id)->exists(), 404);
    }
}
