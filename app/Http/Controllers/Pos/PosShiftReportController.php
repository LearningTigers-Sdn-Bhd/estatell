<?php

namespace App\Http\Controllers\Pos;

use App\Data\Pos\PosShiftData;
use App\Data\Property\CurrencyData;
use App\Http\Controllers\Controller;
use App\Models\PosCashMovement;
use App\Models\PosShift;
use App\Models\Property;
use App\Services\Pos\PosFinanceSummary;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\LaravelData\PaginatedDataCollection;

class PosShiftReportController extends Controller
{
    public function index(Property $property, PosFinanceSummary $summary): Response
    {
        return Inertia::render('finance/pos-shifts/index', [
            'currency' => CurrencyData::forProperty($property),
            'summary' => $summary->shifts($property),
            'shifts' => PosShiftData::collect(PosShift::query()->where('property_id', $property->id)->with(['outlet', 'register', 'openedBy'])->orderByDesc('id')->paginate(25)->through(fn (PosShift $shift): PosShiftData => PosShiftData::fromModel($shift, true)), PaginatedDataCollection::class),
        ]);
    }

    public function show(Property $property, int $shift): Response
    {
        $model = PosShift::query()->where('property_id', $property->id)->with(['outlet', 'register', 'openedBy'])->findOrFail($shift);

        return Inertia::render('finance/pos-shifts/show', [
            'currency' => CurrencyData::forProperty($property),
            // Reports see the expected cash and the variance even while the shift is open; the terminal does not.
            'shift' => PosShiftData::fromModel($model, true),
            'movements' => PosCashMovement::query()->where('pos_shift_id', $model->id)->orderBy('id')->get()->map(fn (PosCashMovement $movement): array => ['id' => $movement->id, 'kind' => $movement->kind->label(), 'amount' => $movement->amount, 'reason' => $movement->reason, 'createdAt' => $movement->created_at->toIso8601String()])->all(),
        ]);
    }
}
