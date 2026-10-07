<?php

namespace App\Http\Controllers\Property;

use App\Actions\Property\UpdatePrintHeader;
use App\Actions\Property\UpdatePrintSettings;
use App\Data\Property\PrintSettingsData;
use App\Data\Property\PrintTaxData;
use App\Data\Property\PropertyProfileData;
use App\Enums\PdfFont;
use App\Enums\PdfFontSize;
use App\Http\Controllers\Controller;
use App\Http\Requests\Property\UpdatePrintHeaderRequest;
use App\Http\Requests\Property\UpdatePrintSettingsRequest;
use App\Models\Property;
use App\Models\Tax;
use App\Services\Property\PrintedPdf;
use Illuminate\Http\RedirectResponse;
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
            'fonts' => PdfFont::options(),
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

    /**
     * The real dompdf output of the saved settings, with a sample report table, shown inline.
     */
    public function sample(Property $property, PrintedPdf $printedPdf): Response
    {
        abort_if($property->printSetting === null, 404);

        return $printedPdf->make($property, 'pdf.print-sample', [
            'currency' => $property->settings?->currency->value ?? '',
        ])->stream('sample.pdf');
    }

    /**
     * Each tab has its own URL. Both tabs draw the same preview, so both get the settings, the profile and the taxes.
     *
     * @param  'general'|'header'  $tab
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
            ...$tabProps,
        ]);
    }
}
