<?php

namespace App\Http\Controllers\Property;

use App\Actions\Property\UpdatePrintDocuments;
use App\Actions\Property\UpdatePrintHeader;
use App\Actions\Property\UpdatePrintSettings;
use App\Data\Property\PrintSettingsData;
use App\Data\Property\PrintTaxData;
use App\Data\Property\PropertyProfileData;
use App\Enums\PdfFont;
use App\Enums\PdfFontSize;
use App\Http\Controllers\Controller;
use App\Http\Requests\Property\UpdatePrintDocumentsRequest;
use App\Http\Requests\Property\UpdatePrintHeaderRequest;
use App\Http\Requests\Property\UpdatePrintSettingsRequest;
use App\Models\Property;
use App\Models\Tax;
use App\Services\Property\PrintedPdf;
use App\Services\Property\SampleDocument;
use Brick\Money\Currency;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Printing settings: how every PDF of the property looks. The forms save on every change, so a success shows no
 * toast. A property gets its print settings at Basics, so before that these routes are a 404.
 * See docs/02-property/05-printing-settings.md.
 */
class PrintSettingsController extends Controller
{
    /**
     * Printing page, General tab: font, font size and footer.
     */
    public function general(Property $property): InertiaResponse|RedirectResponse
    {
        if ($property->printSetting === null) {
            return to_route('settings.edit', $property);
        }

        return $this->renderTab($property, 'general', [
            'fontSizes' => PdfFontSize::options(),
        ]);
    }

    /**
     * Printing page, Header tab: the letterhead switches and the tax numbers, in the Taxes tab order.
     */
    public function header(Property $property): InertiaResponse|RedirectResponse
    {
        if ($property->printSetting === null) {
            return to_route('settings.edit', $property);
        }

        return $this->renderTab($property, 'header');
    }

    /**
     * Printing page, Documents tab: the switches the booking summary, the folio invoice and the AR invoice read.
     */
    public function documents(Property $property): InertiaResponse|RedirectResponse
    {
        if ($property->printSetting === null) {
            return to_route('settings.edit', $property);
        }

        return $this->renderTab($property, 'documents');
    }

    public function update(UpdatePrintSettingsRequest $request, Property $property, UpdatePrintSettings $updatePrintSettings): RedirectResponse
    {
        abort_if($property->printSetting === null, 404);

        $updatePrintSettings($request->user(), $property, $request->validated());

        return back();
    }

    public function updateHeader(UpdatePrintHeaderRequest $request, Property $property, UpdatePrintHeader $updatePrintHeader): RedirectResponse
    {
        abort_if($property->printSetting === null, 404);

        if (! $updatePrintHeader($request->user(), $property, $request->switches(), $request->taxIds())) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('The header could not be saved. Reload the page and try again.')]);
        }

        return back();
    }

    public function updateDocuments(UpdatePrintDocumentsRequest $request, Property $property, UpdatePrintDocuments $updatePrintDocuments): RedirectResponse
    {
        abort_if($property->printSetting === null, 404);

        $updatePrintDocuments($request->user(), $property, $request->validated());

        return back();
    }

    /**
     * The real dompdf output of the saved settings, shown inline: a sample report table, or with
     * `?document=booking-summary` a sample booking summary that reads the document switches.
     */
    public function sample(Request $request, Property $property, PrintedPdf $printedPdf, SampleDocument $sampleDocument): Response
    {
        abort_if($property->printSetting === null, 404);

        if ($request->query('document') === 'booking-summary') {
            return $printedPdf->make($property, 'pdf.document-sample', [
                'currency' => $property->settings?->currency ?? Currency::of('MYR'),
                'groups' => $sampleDocument->groups($property->printSetting),
                'settings' => $property->printSetting,
            ])->stream('sample-booking-summary.pdf');
        }

        return $printedPdf->make($property, 'pdf.print-sample', [
            'currency' => $property->settings?->currency->getCurrencyCode() ?? '',
        ])->stream('sample.pdf');
    }

    /**
     * Each tab has its own URL. All tabs draw the same preview, so both get the settings, the profile, the taxes and
     * the font styles.
     *
     * @param  'general'|'header'|'documents'  $tab
     * @param  array<string, mixed>  $tabProps
     */
    private function renderTab(Property $property, string $tab, array $tabProps = []): InertiaResponse
    {
        $property->load(['printSetting.taxes', 'media']);

        return Inertia::render('settings/printing/edit', [
            'tab' => $tab,
            'settings' => PrintSettingsData::fromModel($property->printSetting),
            'profile' => PropertyProfileData::fromModel($property),
            'taxes' => $property->taxes()
                ->orderByDesc('enabled')->orderBy('name')->orderBy('id')
                ->get()
                ->map(fn (Tax $tax): PrintTaxData => PrintTaxData::fromModel($tax)),
            'fonts' => PdfFont::options(),
            ...$tabProps,
        ]);
    }
}
