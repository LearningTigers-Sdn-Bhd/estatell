<?php

namespace App\Actions\Pos;

use App\Models\PosOutlet;
use App\Models\Property;
use App\Models\TransactionCode;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Save a native outlet's accounting codes (service charge, packaging, rounding, cash variance, safe clearing), cash
 * rounding, room-charge rules and till controls. Every code must be the property's (`transaction_code_invalid`) and the
 * outlet must be a native one (`outlet_invalid`). A refusal writes nothing. See docs/34-native-pos/05-shifts-audit-and-einvoice.md.
 */
class SavePosOutletSettings
{
    private const array CODES = ['service_charge_transaction_code_id', 'packaging_transaction_code_id', 'rounding_transaction_code_id', 'cash_variance_transaction_code_id', 'safe_clearing_transaction_code_id'];

    /**
     * @param  array<string, mixed>  $values  Keys of `PosOutlet`'s fillable settings columns.
     */
    public function __invoke(User $actor, Property $property, PosOutlet $outlet, array $values): PosActionResult
    {
        return DB::transaction(function () use ($actor, $property, $outlet, $values): PosActionResult {
            if ($outlet->property_id !== $property->id || ! $outlet->native_pos_enabled) {
                return PosActionResult::refused('outlet_invalid');
            }

            $codes = array_filter(array_intersect_key($values, array_flip(self::CODES)), fn ($id): bool => $id !== null);

            if (TransactionCode::query()->where('property_id', $property->id)->whereIn('id', $codes)->count() !== count(array_unique($codes))) {
                return PosActionResult::refused('transaction_code_invalid');
            }

            $outlet->fill(array_intersect_key($values, array_flip([...self::CODES, 'sst_registration_number', 'operation_mode', 'rounding_mode', 'rounding_policy', 'room_charge_limit', 'require_room_charge_signature', 'require_meal_redemption_confirmation', 'require_reprint_pin', 'require_dual_control', 'print_receipt_after_payment', 'print_drawer_slip'])))->save();

            activity()->performedOn($outlet)->causedBy($actor)->event('pos.outlet_settings_updated')->withProperties(['property_id' => $property->id])->log('POS outlet settings updated');

            return PosActionResult::saved($outlet);
        });
    }
}
