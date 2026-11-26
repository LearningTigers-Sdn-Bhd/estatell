<?php

namespace App\Http\Controllers\Pos;

use App\Data\Pos\PosShiftData;
use App\Data\Property\CurrencyData;
use App\Http\Controllers\Controller;
use App\Models\PosCashMovement;
use App\Models\PosOutlet;
use App\Models\PosShift;
use App\Models\Property;
use App\Services\Pos\PosFinanceSummary;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\LaravelData\PaginatedDataCollection;

class PosShiftReportController extends Controller
{
    public function index(Request $request, Property $property, PosFinanceSummary $summary): Response
    {
        $filters = $request->validate([
            'outlet' => ['nullable', 'integer'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        // A shift is dated by the business date it opened on.
        $shifts = PosShift::query()->where('property_id', $property->id)
            ->when($filters['outlet'] ?? null, fn ($query, $outlet) => $query->where('pos_outlet_id', (int) $outlet))
            ->when($filters['from'] ?? null, fn ($query, $from) => $query->whereDate('opened_business_date', '>=', $from))
            ->when($filters['to'] ?? null, fn ($query, $to) => $query->whereDate('opened_business_date', '<=', $to));

        return Inertia::render('finance/pos-shifts/index', [
            'currency' => CurrencyData::forProperty($property),
            'summary' => $summary->shifts(clone $shifts),
            'outlets' => PosOutlet::query()->where('property_id', $property->id)->where('pos_enabled', true)->orderBy('name')->get(['id', 'name']),
            'filters' => array_filter($filters),
            'shifts' => PosShiftData::collect($shifts->with(['outlet', 'register', 'openedBy'])->orderByDesc('id')->paginate(25)->withQueryString()->through(fn (PosShift $shift): PosShiftData => PosShiftData::fromModel($shift, true)), PaginatedDataCollection::class),
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
