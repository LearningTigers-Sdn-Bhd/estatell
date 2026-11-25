<?php

namespace App\Actions\Pos;

use App\Enums\PosQrSessionKind;
use App\Models\PosQrSession;
use App\Models\PosTable;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Print a dynamic table QR. Any token the table already had is revoked first, so one table never has two live QR codes. The
 * token lives for the outlet's `dynamic_qr_ttl_minutes`, and until the table is settled. A refusal writes nothing
 * (`table_invalid`, `outlet_inactive`). See docs/34-native-pos/06-kitchen-kds-and-qr.md.
 */
class IssueTableQrSession
{
    public function __invoke(User $actor, Property $property, PosTable $table): PosActionResult
    {
        return DB::transaction(function () use ($actor, $property, $table): PosActionResult {
            $table = PosTable::query()->where('property_id', $property->id)->with('floor.outlet')->whereKey($table->id)->lockForUpdate()->first();

            if ($table === null || ! $table->active || ! $table->floor->active) {
                return PosActionResult::refused('table_invalid');
            }

            $outlet = $table->floor->outlet;

            if (! $outlet->active || ! $outlet->pos_enabled) {
                return PosActionResult::refused('outlet_inactive');
            }

            PosQrSession::query()
                ->where('property_id', $property->id)
                ->where('kind', PosQrSessionKind::Table->value)
                ->where('pos_table_id', $table->id)
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);

            $session = new PosQrSession([
                'kind' => PosQrSessionKind::Table,
                'token' => Str::random(32),
                'single_use' => $outlet->dynamic_qr_single_use,
                'expires_at' => now()->addMinutes($outlet->dynamic_qr_ttl_minutes),
                'created_by_user_id' => $actor->id,
            ]);
            $session->property_id = $property->id;
            $session->pos_outlet_id = $outlet->id;
            $session->pos_table_id = $table->id;
            $session->save();

            activity()->performedOn($session)->causedBy($actor)->event('pos.table_qr_issued')
                ->withProperties(['property_id' => $property->id, 'pos_outlet_id' => $outlet->id, 'pos_table_id' => $table->id])
                ->log('POS table QR issued');

            return PosActionResult::saved($session);
        });
    }
}
