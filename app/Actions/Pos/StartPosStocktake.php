<?php

namespace App\Actions\Pos;

use App\Enums\PosStocktakeStatus;
use App\Models\PosOutlet;
use App\Models\PosStocktake;
use App\Models\Property;
use App\Models\User;
use App\Services\NightAudit\CurrentBusinessDate;
use Illuminate\Support\Facades\DB;

/**
 * Open a closing count for an outlet on the open business date. A refusal writes nothing (`outlet_invalid`, `no_business_date`).
 */
class StartPosStocktake
{
    public function __construct(private CurrentBusinessDate $currentBusinessDate) {}

    public function __invoke(User $actor, Property $property, PosOutlet $outlet, ?string $note = null): PosActionResult
    {
        return DB::transaction(function () use ($actor, $property, $outlet, $note): PosActionResult {
            if ($outlet->property_id !== $property->id) {
                return PosActionResult::refused('outlet_invalid');
            }

            $businessDate = $this->currentBusinessDate->date($property);

            if ($businessDate === null) {
                return PosActionResult::refused('no_business_date');
            }

            $stocktake = new PosStocktake;
            $stocktake->fill([
                'pos_outlet_id' => $outlet->id,
                'business_date' => $businessDate->toDateString(),
                'status' => PosStocktakeStatus::Draft,
                'note' => $note === null || trim($note) === '' ? null : trim($note),
                'created_by_user_id' => $actor->id,
            ]);
            $stocktake->property_id = $property->id;
            $stocktake->save();

            activity()->performedOn($stocktake)->causedBy($actor)->event('pos.stocktake_started')
                ->withProperties(['property_id' => $property->id, 'pos_outlet_id' => $outlet->id])
                ->log('POS stocktake started');

            return PosActionResult::saved($stocktake);
        });
    }
}
