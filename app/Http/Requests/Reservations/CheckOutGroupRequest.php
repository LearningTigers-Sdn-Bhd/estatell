<?php

namespace App\Http\Requests\Reservations;

use App\Actions\FrontDesk\GroupCheckOutRow;
use App\Actions\FrontDesk\OnePayment;
use App\Data\Reservations\CheckOutCollectData;
use App\Enums\PermissionEnum;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Services\FrontDesk\CheckOutSettlement;
use App\Services\Platform\Impersonation;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;
use Illuminate\Validation\Validator;

/**
 * The group check-out save: the UUID of the save, one entry for each room with its settle choice (`collect`, `refund`
 * or `move`, or none), One payment for all and the Master settle. Only the shape and the per-choice permissions are
 * checked here: Collect needs `payment.record`, Refund `payment.refund`, Add to Master `folio.transfer`, and a move
 * of a folio that holds payments needs `payment.refund` and payments that can be refunded. The settle needs
 * `city-ledger.invoice`. A unit of another reservation is a 404 in the controller. `CheckOutGroup` refuses with a code.
 */
class CheckOutGroupRequest extends FormRequest
{
    /**
     * The `can:front-desk.check-out` route middleware authorizes this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'uuid'],
            'rows' => ['required', 'array', 'min:1', 'max:300'],
            'rows.*.unit_id' => ['required', 'integer', 'distinct'],
            'rows.*.choice' => ['nullable', 'in:collect,refund,move'],
            'rows.*.payment_method_id' => ['nullable', 'integer'],
            'rows.*.reference' => ['nullable', 'string', 'max:100'],
            'rows.*.amount' => ['nullable', 'regex:/^\d+(\.\d{1,2})?$/'],
            'one_payment' => ['nullable', 'array'],
            'one_payment.payer_unit_id' => ['required_with:one_payment', 'integer'],
            'one_payment.payment_method_id' => ['required_with:one_payment', 'integer'],
            'one_payment.reference' => ['nullable', 'string', 'max:100'],
            'one_payment.amount' => ['required_with:one_payment', 'regex:/^\d+(\.\d{1,2})?$/'],
            'settle_master' => ['boolean'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $user = $this->user();
            $onePayment = $this->filled('one_payment');
            $units = $this->reservationUnits();

            if ($onePayment && ! ($user->can(PermissionEnum::PaymentRecord->value) && $user->can(PermissionEnum::FolioTransfer->value))) {
                $validator->errors()->add('one_payment', __('You may not take one payment for all rooms.'));
            }

            if ($this->boolean('settle_master') && ! $user->can(PermissionEnum::CityLedgerInvoice->value)) {
                $validator->errors()->add('settle_master', __('You may not settle the Master folio to the city ledger.'));
            }

            foreach ($this->array('rows') as $row) {
                $unitId = (int) $row['unit_id'];
                $choice = $row['choice'] ?? null;
                $key = 'units.'.$unitId;

                if ($choice === 'collect' && ! $onePayment && (empty($row['payment_method_id']) || ! isset($row['amount']))) {
                    $validator->errors()->add($key, __('Choose a payment method and an amount.'));
                } elseif (in_array($choice, ['collect', 'refund'], true) && ! isset($row['amount'])) {
                    $validator->errors()->add($key, __('Enter an amount.'));
                }

                if ($choice === 'collect' && ! $onePayment && ! $user->can(PermissionEnum::PaymentRecord->value)) {
                    $validator->errors()->add($key, __('You may not collect payments.'));
                }

                if ($choice === 'refund' && ! $user->can(PermissionEnum::PaymentRefund->value)) {
                    $validator->errors()->add($key, __('You may not refund payments.'));
                } elseif ($choice === 'refund' && ! app(Impersonation::class)->canCorrectMoney()) {
                    $validator->errors()->add($key, __('The owner has not allowed refunds and voids during support access'));
                }

                if ($choice === 'move' && ! $user->can(PermissionEnum::FolioTransfer->value)) {
                    $validator->errors()->add($key, __('You may not move charges.'));
                }

                $moves = $choice === 'move' || ($onePayment && $choice === 'collect');
                $unit = $units->get($unitId);

                if ($moves && $unit !== null) {
                    $settlement = app(CheckOutSettlement::class);
                    $folios = $settlement->folios($unit);

                    if ($settlement->heldPayments($folios)->isNotEmpty() && ! $user->can(PermissionEnum::PaymentRefund->value)) {
                        $validator->errors()->add($key, __('This room holds payments that must be refunded to move its charges. You may not refund payments.'));
                    } elseif ($settlement->heldPayments($folios)->isNotEmpty() && ! app(Impersonation::class)->canCorrectMoney()) {
                        $validator->errors()->add($key, __('The owner has not allowed refunds and voids during support access'));
                    } elseif ($settlement->holdsUnrefundable($folios)) {
                        $validator->errors()->add($key, __('This room holds a payment that cannot be refunded, so its charges cannot move.'));
                    }
                }
            }
        }];
    }

    /**
     * @return list<GroupCheckOutRow>
     */
    public function groupRows(): array
    {
        $uuid = $this->string('idempotency_key')->toString() ?: 'preview';
        $rows = [];

        foreach ($this->array('rows') as $row) {
            $unitId = (int) $row['unit_id'];
            $choice = $row['choice'] ?? null;
            $collect = $choice === 'collect'
                ? new CheckOutCollectData((int) ($row['payment_method_id'] ?? 0), (string) $row['amount'], filled($row['reference'] ?? null) ? (string) $row['reference'] : null, $uuid.':'.$unitId)
                : null;

            $rows[] = new GroupCheckOutRow($unitId, $uuid, $collect, $choice === 'refund' ? (string) $row['amount'] : null, $choice === 'move');
        }

        return $rows;
    }

    public function onePayment(): ?OnePayment
    {
        if (! $this->filled('one_payment')) {
            return null;
        }

        $payment = $this->array('one_payment');

        return new OnePayment((int) $payment['payer_unit_id'], (int) $payment['payment_method_id'], filled($payment['reference'] ?? null) ? (string) $payment['reference'] : null, (string) $payment['amount'], $this->string('idempotency_key')->toString());
    }

    public function settleMaster(): bool
    {
        return $this->boolean('settle_master');
    }

    /**
     * The unit ids of the rows, for the 404 check.
     *
     * @return list<int>
     */
    public function unitIds(): array
    {
        return array_values(array_map(fn (array $row): int => (int) $row['unit_id'], $this->array('rows')));
    }

    /**
     * @return Collection<int, ReservationUnit>
     */
    protected function reservationUnits(): Collection
    {
        /** @var Reservation $reservation */
        $reservation = $this->route('reservation');

        return $reservation->units()->whereIn('id', $this->unitIds())->get()->keyBy('id');
    }
}
