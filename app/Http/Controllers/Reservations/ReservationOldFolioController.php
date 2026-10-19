<?php

namespace App\Http\Controllers\Reservations;

use App\Actions\Folio\AddOldFolio;
use App\Actions\Folio\PostingResult;
use App\Actions\Folio\SaveOldFolios;
use App\Actions\Folio\SaveOldFoliosResult;
use App\Data\Folio\OldFolioRowData;
use App\Enums\PermissionEnum;
use App\Http\Controllers\Controller;
use App\Http\Requests\Folio\StoreOldFolioFolioRequest;
use App\Http\Requests\Folio\StoreOldFoliosRequest;
use App\Models\Company;
use App\Models\Folio;
use App\Models\Guest;
use App\Models\Property;
use App\Models\Reservation;
use App\Services\CityLedger\CompanyOptions;
use App\Services\Folio\FolioRefusals;
use App\Services\Folio\OldFolioPage;
use App\Services\Payments\PaymentRefusals;
use App\Services\Property\MigrationWindow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The old folio page of a reservation: the Open folios as tabs where staff copy the lines of the old system, the save
 * and the added folios. A folio or room of another reservation is a 404. See docs/12-reservations/12-past-and-migrated-stays.md#copy-line-by-line.
 */
class ReservationOldFolioController extends Controller
{
    /**
     * The page. After the migration window it goes back to the folio tab with an error toast, so the old lines are read
     * only there. It writes nothing: a room without a folio gets a tab, and its folio is made when the page is saved.
     */
    public function show(Request $request, Property $property, Reservation $reservation, OldFolioPage $page, MigrationWindow $migrationWindow, FolioRefusals $refusals): Response|RedirectResponse
    {
        if (! $migrationWindow->isOpen($property)) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $refusals->message(PostingResult::REFUSAL_MIGRATION_WINDOW_CLOSED)]);

            return redirect(route('reservations.folio', ['property' => $property, 'reservation' => $reservation]));
        }

        $property->loadMissing('settings');

        return Inertia::render('reservations/old-folio', [
            'oldFolio' => $page->build($request->user(), $property, $reservation),
            'companies' => Inertia::optional(fn () => $request->user()->can(PermissionEnum::CompanyView->value) ? app(CompanyOptions::class)->active($property) : []),
        ]);
    }

    /**
     * Save every tab. A refusal goes back to the page, on the row that caused it, and writes nothing. On success the
     * folio tab opens with a toast.
     */
    public function store(StoreOldFoliosRequest $request, Property $property, Reservation $reservation, SaveOldFolios $saveOldFolios, FolioRefusals $folioRefusals, PaymentRefusals $paymentRefusals): RedirectResponse
    {
        $rows = $request->rows();
        $legacyNumbers = $request->legacyNumbers();
        $folioIds = $reservation->folios()->pluck('id')->all();
        $unitIds = $reservation->units()->pluck('id')->all();

        $knows = fn (int $folioId): bool => $folioId < 0 ? in_array(-$folioId, $unitIds, true) : in_array($folioId, $folioIds, true);

        foreach ($rows as $row) {
            abort_unless($knows($row->folioId), 404);
            abort_unless($row->unitId === null || in_array($row->unitId, $unitIds, true), 404);
        }

        abort_unless(array_all(array_keys($legacyNumbers), $knows), 404);

        $result = $saveOldFolios($request->user(), $reservation, $rows, $legacyNumbers);

        if ($result->isRefused()) {
            return back(fallback: route('reservations.old-folio', ['property' => $property, 'reservation' => $reservation]))
                ->withErrors($this->errors($result, $rows, $folioRefusals, $paymentRefusals));
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $this->savedMessage($result)]);

        return redirect(route('reservations.folio', ['property' => $property, 'reservation' => $reservation]));
    }

    private function savedMessage(SaveOldFoliosResult $result): string
    {
        $parts = [];

        if ($result->lineCount > 0) {
            $parts[] = trans_choice(':count old folio line saved|:count old folio lines saved', $result->lineCount);
        }

        if ($result->removedCount > 0) {
            $parts[] = trans_choice(':count line removed|:count lines removed', $result->removedCount);
        }

        return $parts === [] ? ($result->replayed ? __('The old folios have no changes to save.') : __('Old folios saved.')) : implode(', ', $parts).'.';
    }

    /**
     * Add a Master folio or a third-party folio. A refusal is an error toast. A guest of another property is a 404.
     */
    public function storeFolio(StoreOldFolioFolioRequest $request, Property $property, Reservation $reservation, AddOldFolio $addOldFolio, FolioRefusals $refusals): RedirectResponse
    {
        $guest = $request->filled('payer_guest_id')
            ? Guest::query()->where('property_id', $property->id)->notMerged()->whereNull('anonymized_at')->findOrFail($request->integer('payer_guest_id'))
            : null;
        $company = $request->filled('payer_company_id') ? Company::query()->where('property_id', $property->id)->findOrFail($request->integer('payer_company_id')) : null;

        $folio = $addOldFolio($request->user(), $reservation, $guest, $company?->id);

        if (! $folio instanceof Folio) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $refusals->message($folio)]);

            return back(fallback: route('reservations.old-folio', ['property' => $property, 'reservation' => $reservation]));
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Folio :number added.', ['number' => $folio->number])]);

        return redirect(route('reservations.old-folio', ['property' => $property, 'reservation' => $reservation]));
    }

    /**
     * The refusal as form errors: a row's refusal goes on that row's field (`rows.{index}.{field}`), a refusal of the
     * whole save on `form`.
     *
     * @param  list<OldFolioRowData>  $rows
     * @return array<string, string>
     */
    private function errors(SaveOldFoliosResult $result, array $rows, FolioRefusals $folioRefusals, PaymentRefusals $paymentRefusals): array
    {
        $code = (string) $result->refusal;
        $message = in_array($code, ['method_not_available', 'reference_required'], true) ? $paymentRefusals->message($code) : $folioRefusals->message($code);
        $index = $result->rowKey === null ? false : array_search($result->rowKey, array_map(fn (OldFolioRowData $row): string => $row->key, $rows), true);

        if ($index === false) {
            return ['form' => $message];
        }

        $field = match ($code) {
            PostingResult::REFUSAL_SERVICE_DATE_INVALID, SaveOldFoliosResult::REFUSAL_DATE_NOT_BEFORE_GO_LIVE => 'service_date',
            PostingResult::REFUSAL_AMOUNT_INVALID => 'amount',
            PostingResult::REFUSAL_QUANTITY_INVALID => 'quantity',
            SaveOldFoliosResult::REFUSAL_DESCRIPTION_REQUIRED, 'reference_required' => 'description',
            default => 'item',
        };

        return ['rows.'.$index.'.'.$field => $message];
    }
}
