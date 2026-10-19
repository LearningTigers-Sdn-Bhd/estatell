<?php

namespace App\Actions\Folio;

use App\Actions\Payments\RecordPayment;
use App\Actions\Payments\RefundPayment;
use App\Data\Folio\ManualRowData;
use App\Data\Folio\NewChargeData;
use App\Data\Payments\NewPaymentData;
use App\Enums\FolioType;
use App\Enums\ManualRowType;
use App\Enums\PaymentPurpose;
use App\Models\Folio;
use App\Models\FolioManualBatch;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PostManualBatch
{
    public function __construct(
        private PostCharge $postCharge,
        private RecordPayment $recordPayment,
        private RefundPayment $refundPayment,
        private CloseFolio $closeFolio,
    ) {}

    /**
     * Post the rows of the manual folio page as one batch with one reason. In one transaction, with every touched folio
     * locked in id order: create the `folio_manual_batches` row, post each row in order through `PostCharge`,
     * `RecordPayment` or `RefundPayment` (each with its service date and the batch), then `CloseFolio` on `$folio` when
     * `$close` is on. A refusal from any row, or from the close, rolls everything back, including the batch row and the
     * receipt numbers, and names the row. The form's UUID is the `$idempotencyKey`: a repeat returns the existing batch
     * and writes nothing. Payment rows take the Settlement purpose. A refund row posts on the folio of the payment it
     * refunds. The caller (the controller) has found the folio, the payments and the units inside the reservation;
     * anything else is a programming error. Logged on the reservation as `folio.manual_batch_posted`. Permissions are
     * checked by the route. See docs/13-folio/08-manual-folio.md.
     *
     * @param  list<ManualRowData>  $rows
     */
    public function __invoke(User $actor, Reservation $reservation, Folio $folio, string $reason, array $rows, bool $close, string $idempotencyKey): ManualBatchResult
    {
        if ($folio->reservation_id !== $reservation->id) {
            throw new InvalidArgumentException('The folio belongs to another reservation.');
        }

        if ($rows === []) {
            return ManualBatchResult::refused(ManualBatchResult::REFUSAL_NOTHING_TO_POST);
        }

        if (count($rows) > ManualBatchResult::MAX_ROWS) {
            return ManualBatchResult::refused(ManualBatchResult::REFUSAL_TOO_MANY_ROWS);
        }

        try {
            return DB::transaction(function () use ($actor, $reservation, $folio, $reason, $rows, $close, $idempotencyKey): ManualBatchResult {
                $folios = $this->lockFolios($reservation, $folio, $rows);
                $existing = FolioManualBatch::query()->where('idempotency_key', $idempotencyKey)->first();

                if ($existing !== null) {
                    return $existing->property_id === $reservation->property_id && $folios->has($existing->folio_id)
                        ? ManualBatchResult::replayed($existing)
                        : throw new InvalidArgumentException('The batch key belongs to another reservation.');
                }

                $batch = new FolioManualBatch(['reason' => trim($reason), 'idempotency_key' => $idempotencyKey]);
                $batch->property_id = $reservation->property_id;
                $batch->folio_id = $folio->id;
                $batch->user_id = $actor->id;
                $batch->save();

                foreach ($rows as $row) {
                    $refusal = $this->post($actor, $reservation, $folios, $batch, $row);

                    if ($refusal !== null) {
                        throw new ManualBatchRefusal($refusal);
                    }
                }

                $closed = false;

                if ($close) {
                    $result = ($this->closeFolio)($actor, $folio);

                    if ($result->isRefused()) {
                        throw new ManualBatchRefusal(ManualBatchResult::refused((string) $result->refusal, rooms: $result->rooms));
                    }

                    $closed = true;
                }

                activity()
                    ->performedOn($reservation)
                    ->causedBy($actor)
                    ->event('folio.manual_batch_posted')
                    ->withProperties([
                        'property_id' => $reservation->property_id,
                        'folio_id' => $folio->id,
                        'manual_batch_id' => $batch->id,
                        'reason' => $batch->reason,
                        'lines' => count($rows),
                        'closed' => $closed,
                    ])
                    ->log('Manual entries posted to folio');

                return ManualBatchResult::posted($batch, $closed);
            });
        } catch (ManualBatchRefusal $refusal) {
            return $refusal->result;
        }
    }

    /**
     * Lock the managed folio and every folio the rows post on, in id order, and check they belong to the reservation.
     *
     * @param  list<ManualRowData>  $rows
     * @return Collection<int, Folio>
     */
    private function lockFolios(Reservation $reservation, Folio $folio, array $rows): Collection
    {
        $paymentIds = array_values(array_filter(array_map(fn (ManualRowData $row): ?int => $row->type === ManualRowType::Refund ? $row->paymentId : null, $rows)));
        $paymentFolioIds = $paymentIds === [] ? [] : Payment::query()->whereIn('id', $paymentIds)->pluck('folio_id')->all();
        $ids = collect([$folio->id, ...array_map(fn (ManualRowData $row): int => $row->folioId, $rows), ...$paymentFolioIds])->unique()->sort()->values();
        $locked = Folio::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        if ($locked->count() !== $ids->count() || $locked->contains(fn (Folio $locked): bool => $locked->reservation_id !== $reservation->id)) {
            throw new InvalidArgumentException('Every folio must belong to the reservation.');
        }

        return $locked;
    }

    /**
     * Post one row. Returns the refusal that names the row, or null when it posted.
     *
     * @param  Collection<int, Folio>  $folios
     */
    private function post(User $actor, Reservation $reservation, Collection $folios, FolioManualBatch $batch, ManualRowData $row): ?ManualBatchResult
    {
        $key = $batch->idempotency_key.':'.$row->key;

        if ($row->type === ManualRowType::Charge) {
            $target = $folios->get($row->folioId);
            $unit = $this->unit($reservation, $target, $row);

            if ($unit === null) {
                return ManualBatchResult::refused(ManualBatchResult::REFUSAL_UNIT_REQUIRED, $row->key, $row->type);
            }

            $result = ($this->postCharge)($actor, $unit, new NewChargeData(
                chargeItemId: $row->chargeItemId,
                transactionCodeId: null,
                description: $row->description,
                quantity: $row->quantity,
                unitAmount: $row->amount,
                idempotencyKey: 'manual:'.$key,
                folioId: $target->id,
                serviceDate: $row->serviceDate,
                taxIds: $row->taxIds,
                manualBatchId: $batch->id,
            ));

            return $result->isRefused() ? ManualBatchResult::refused((string) $result->refusal, $row->key, $row->type) : null;
        }

        if ($row->type === ManualRowType::Payment) {
            $result = ($this->recordPayment)($actor, $reservation, $folios->get($row->folioId), null, new NewPaymentData(
                paymentMethodId: (int) $row->paymentMethodId,
                purpose: PaymentPurpose::Settlement,
                amount: $row->amount,
                reference: $row->reference,
                idempotencyKey: $key,
                serviceDate: $row->serviceDate,
                manualBatchId: $batch->id,
            ));

            return $result->isRefused() ? ManualBatchResult::refused((string) $result->refusal, $row->key, $row->type) : null;
        }

        $payment = Payment::query()->find($row->paymentId);

        if ($payment === null || ! $folios->has($payment->folio_id)) {
            throw new InvalidArgumentException('The payment must belong to a folio of the reservation.');
        }

        $result = ($this->refundPayment)($actor, $payment, $row->amount, $row->reference, $key, $row->serviceDate, $batch->id);

        return $result->isRefused() ? ManualBatchResult::refused((string) $result->refusal, $row->key, $row->type) : null;
    }

    /**
     * The unit a charge posts for: the folio's own unit on a Unit folio, else the unit the row names. Null when a
     * Master or Third party row names none.
     */
    private function unit(Reservation $reservation, Folio $target, ManualRowData $row): ?ReservationUnit
    {
        $unitId = $target->type === FolioType::Unit ? $target->reservation_unit_id : $row->unitId;

        if ($unitId === null) {
            return null;
        }

        return ReservationUnit::query()->where('reservation_id', $reservation->id)->findOrFail($unitId);
    }
}
