<?php

namespace App\Actions\Pos;

use App\Data\Pos\PosOrderLineInputData;
use App\Enums\PosDiningKind;
use App\Enums\PosOrderSource;
use App\Enums\PosOrderStatus;
use App\Enums\PosQrSessionKind;
use App\Enums\PosShiftStatus;
use App\Enums\UnitStatus;
use App\Models\PosDiningOption;
use App\Models\PosOrder;
use App\Models\PosOutlet;
use App\Models\PosQrSession;
use App\Models\PosShift;
use App\Models\ReservationUnit;
use App\Services\Pos\QrSessionState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A guest's QR order. A table order joins the table's open check, or opens one on the outlet's open shift. A room order joins
 * the unit's open room check at a room-service or poolside outlet, and only while the unit is checked in and can post. The
 * lines are added as Draft, so the cashier sends them to the kitchen with Fire, which is the staff approval. Every line goes
 * through `AddOrderLine`, so price, stock and availability rules are the same as at the register. A refusal writes nothing.
 * See docs/34-native-pos/06-kitchen-kds-and-qr.md.
 */
class PlaceQrOrder
{
    public function __construct(private AddOrderLine $addOrderLine, private QrSessionState $state) {}

    /**
     * @param  list<array{variant_id: int, quantity: int, note: ?string}>  $lines
     */
    public function __invoke(PosQrSession $session, ?PosOutlet $outlet, array $lines, bool $allowNote = false): PosActionResult
    {
        try {
            return DB::transaction(fn (): PosActionResult => $this->place($session, $outlet, $lines, $allowNote));
        } catch (PosRefusedException $refused) {
            return PosActionResult::refused($refused->refusal);
        }
    }

    /**
     * @param  list<array{variant_id: int, quantity: int, note: ?string}>  $lines
     */
    private function place(PosQrSession $session, ?PosOutlet $outlet, array $lines, bool $allowNote): PosActionResult
    {
        $session = PosQrSession::query()->whereKey($session->id)->lockForUpdate()->first();

        if ($session === null) {
            return PosActionResult::refused('qr_invalid');
        }

        $state = ($this->state)($session);

        if ($state !== QrSessionState::ACTIVE) {
            return PosActionResult::refused('qr_'.$state);
        }

        if ($lines === []) {
            return PosActionResult::refused('order_empty');
        }

        $isTable = $session->kind === PosQrSessionKind::Table;
        $outlet = $isTable ? $session->outlet : $outlet;

        if ($outlet === null || $outlet->property_id !== $session->property_id || ! $outlet->pos_enabled || ! $outlet->active) {
            return PosActionResult::refused('outlet_invalid');
        }

        $shift = PosShift::query()->where('pos_outlet_id', $outlet->id)->where('status', PosShiftStatus::Open->value)->first();

        if ($shift === null) {
            return PosActionResult::refused('outlet_closed');
        }

        $unit = null;

        if ($isTable) {
            if ($session->table === null || ! $session->table->active) {
                return PosActionResult::refused('table_invalid');
            }

            $diningOption = PosDiningOption::query()->where('pos_outlet_id', $outlet->id)->where('kind', PosDiningKind::DineIn->value)->where('active', true)->first();
        } else {
            $unit = ReservationUnit::query()->where('property_id', $session->property_id)->find($session->reservation_unit_id);

            if ($unit === null || $unit->status !== UnitStatus::CheckedIn || $unit->no_post) {
                return PosActionResult::refused('unit_not_postable');
            }

            $diningOption = PosDiningOption::query()->where('pos_outlet_id', $outlet->id)->whereIn('kind', [PosDiningKind::RoomService->value, PosDiningKind::Poolside->value])->where('active', true)
                ->orderByRaw("case when kind = 'room_service' then 0 else 1 end")->first();
        }

        if ($diningOption === null) {
            return PosActionResult::refused('dining_option_invalid');
        }

        $order = $this->openCheck($session, $outlet, $shift, $diningOption, $unit);

        foreach ($lines as $line) {
            $result = ($this->addOrderLine)(null, $session->property()->firstOrFail(), $order, new PosOrderLineInputData(
                variantId: $line['variant_id'],
                quantity: $line['quantity'],
                note: $allowNote ? $line['note'] : null,
            ));

            if ($result->isRefused()) {
                throw new PosRefusedException((string) $result->refusal);
            }
        }

        return PosActionResult::saved($order->refresh());
    }

    private function openCheck(PosQrSession $session, PosOutlet $outlet, PosShift $shift, PosDiningOption $diningOption, ?ReservationUnit $unit): PosOrder
    {
        $existing = PosOrder::query()
            ->where('property_id', $session->property_id)
            ->where('pos_outlet_id', $outlet->id)
            ->where('status', PosOrderStatus::Open->value)
            ->when($unit === null, fn ($query) => $query->where('pos_table_id', $session->pos_table_id), fn ($query) => $query->where('reservation_unit_id', $unit->id)->where('source', PosOrderSource::RoomQr->value))
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $order = new PosOrder;
        $order->guest_count = 1;
        $order->source = $unit === null ? PosOrderSource::TableQr : PosOrderSource::RoomQr;
        $order->property_id = $session->property_id;
        $order->pos_outlet_id = $outlet->id;
        $order->pos_shift_id = $shift->id;
        $order->client_uuid = (string) Str::uuid();
        $order->pos_dining_option_id = $diningOption->id;
        $order->pos_table_id = $unit === null ? $session->pos_table_id : null;
        $order->reservation_unit_id = $unit?->id;
        $order->status = PosOrderStatus::Open;
        $order->opened_at = now();
        $order->save();

        return $order;
    }
}
