<?php

namespace App\Http\Controllers\Reservations;

use App\Actions\Folio\ManualBatchResult;
use App\Actions\Folio\PostManualBatch;
use App\Data\Folio\ManualPreviewData;
use App\Data\Folio\ManualRowData;
use App\Enums\FolioStatus;
use App\Enums\ManualRowType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Folio\ManualFolioPreviewRequest;
use App\Http\Requests\Folio\StartManualFolioRequest;
use App\Http\Requests\Folio\StoreManualFolioRequest;
use App\Models\Folio;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Reservation;
use App\Services\Folio\FolioRefusals;
use App\Services\Folio\ManualFolioPage;
use App\Services\Folio\ManualFolioPreview;
use App\Services\Payments\PaymentRefusals;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The manual folio page of a folio: the reason dialog's start, the page, the preview of its draft rows and the post.
 * A folio, payment or room of another reservation is a 404. See docs/13-folio/08-manual-folio.md.
 */
class ReservationFolioManualController extends Controller
{
    private const string REASON_KEY = 'manual_folio_reason';

    /**
     * Keep the reason for the page that comes next and open it. Nothing is written.
     */
    public function start(StartManualFolioRequest $request, Property $property, Reservation $reservation, Folio $folio): RedirectResponse
    {
        $request->session()->flash(self::REASON_KEY, trim($request->string('reason')->toString()));

        return redirect(route('reservations.folios.manual', ['property' => $property, 'reservation' => $reservation, 'folio' => $folio]));
    }

    /**
     * The page. A folio that is not Open goes back to the folio tab with an error toast. A page opened without a
     * reason (a reload or the address) asks for it first.
     */
    public function show(Request $request, Property $property, Reservation $reservation, Folio $folio, ManualFolioPage $page, FolioRefusals $refusals): Response|RedirectResponse
    {
        if ($folio->status !== FolioStatus::Open) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $refusals->message('folio_not_open')]);

            return redirect(route('reservations.folio', ['property' => $property, 'reservation' => $reservation, 'folio' => $folio->id]));
        }

        $reservation->loadMissing('booker');

        return Inertia::render('reservations/manual-folio', [
            'manual' => $page->build($request->user(), $property, $reservation, $folio, $request->session()->get(self::REASON_KEY)),
        ]);
    }

    /**
     * The tax lines, total and refusal of every draft row, and the rail figures. It writes nothing.
     */
    public function preview(ManualFolioPreviewRequest $request, Property $property, Reservation $reservation, Folio $folio, ManualFolioPreview $preview): JsonResponse
    {
        $rows = $request->rows();
        $this->scope($reservation, $rows);

        return response()->json($this->previewData($preview, $property, $reservation, $folio, $rows));
    }

    /**
     * Post the rows as one batch. A refusal goes back to the page, on the row that caused it, and writes nothing. On
     * success it opens the folio tab on the folio with a toast.
     */
    public function store(StoreManualFolioRequest $request, Property $property, Reservation $reservation, Folio $folio, PostManualBatch $postManualBatch, FolioRefusals $folioRefusals, PaymentRefusals $paymentRefusals): RedirectResponse
    {
        $rows = $request->rows();
        $this->scope($reservation, $rows);
        $result = $postManualBatch($request->user(), $reservation, $folio, $request->string('reason')->toString(), $rows, $request->boolean('close'), $request->string('idempotency_key')->toString());

        if ($result->isRefused()) {
            return back(fallback: route('reservations.folios.manual', ['property' => $property, 'reservation' => $reservation, 'folio' => $folio]))
                ->withErrors($this->errors($result, $rows, $folioRefusals, $paymentRefusals));
        }

        $count = count($rows);
        Inertia::flash('toast', ['type' => 'success', 'message' => $count === 1 ? __('1 line posted.') : __(':count lines posted.', ['count' => $count])]);

        return redirect(route('reservations.folio', ['property' => $property, 'reservation' => $reservation, 'folio' => $folio->id]));
    }

    /**
     * A folio, payment or room named by a row must belong to the reservation.
     *
     * @param  list<ManualRowData>  $rows
     */
    private function scope(Reservation $reservation, array $rows): void
    {
        $folioIds = $reservation->folios()->pluck('id')->all();
        $unitIds = $reservation->units()->pluck('id')->all();

        foreach ($rows as $row) {
            abort_unless(in_array($row->folioId, $folioIds, true), 404);
            abort_unless($row->unitId === null || in_array($row->unitId, $unitIds, true), 404);
            abort_unless($row->paymentId === null || Payment::query()->whereIn('folio_id', $folioIds)->whereKey($row->paymentId)->exists(), 404);
        }
    }

    /**
     * @param  list<ManualRowData>  $rows
     */
    private function previewData(ManualFolioPreview $preview, Property $property, Reservation $reservation, Folio $folio, array $rows): ManualPreviewData
    {
        return $preview->preview($property, $reservation, $folio, $rows);
    }

    /**
     * The refusal as form errors: a row's refusal goes on that row's field (`rows.{index}.{field}`), a refusal of the
     * whole batch or of the close on `form`.
     *
     * @param  list<ManualRowData>  $rows
     * @return array<string, string>
     */
    private function errors(ManualBatchResult $result, array $rows, FolioRefusals $folioRefusals, PaymentRefusals $paymentRefusals): array
    {
        $errors = match ($result->rowType) {
            ManualRowType::Payment, ManualRowType::Refund => $paymentRefusals->errors((string) $result->refusal),
            default => $folioRefusals->errors((string) $result->refusal, $result->rooms),
        };

        $field = (string) array_key_first($errors);
        $message = $errors[$field];

        if ($result->rowKey === null) {
            return ['form' => $message];
        }

        $index = array_search($result->rowKey, array_map(fn (ManualRowData $row): string => $row->key, $rows), true);

        return ['rows.'.$index.'.'.$field => $message];
    }
}
