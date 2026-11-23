<?php

namespace App\Http\Controllers\Pos;

use App\Actions\Pos\ResolvePendingPosPosting;
use App\Data\Pos\PosPostingDetailData;
use App\Data\Pos\PosPostingRowData;
use App\Data\Pos\ResolvePosPostingData;
use App\Data\Property\CurrencyData;
use App\Enums\PosPostingStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\ResolvePosPostingRequest;
use App\Models\PosPosting;
use App\Models\Property;
use App\Services\Pos\InHouseGuestLookup;
use App\Services\Pos\PosRefusalMessages;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PosPostingController extends Controller
{
    public function index(Request $request, Property $property): Response
    {
        $status = PosPostingStatus::tryFrom((string) $request->query('status'));
        $postings = PosPosting::query()
            ->where('property_id', $property->id)
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->with(['outlet', 'reservationUnit.reservation'])
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('finance/pos-postings/index', [
            'postings' => PosPostingRowData::collect($postings->through(PosPostingRowData::fromModel(...))),
            'status' => $status?->value,
            'statuses' => PosPostingStatus::options(),
            'currency' => CurrencyData::forProperty($property),
            'pendingCount' => PosPosting::query()->where('property_id', $property->id)->where('status', PosPostingStatus::PendingReview)->count(),
        ]);
    }

    public function show(Request $request, Property $property, int $posting, InHouseGuestLookup $lookup): Response
    {
        $model = PosPosting::query()
            ->where('property_id', $property->id)
            ->with(['outlet', 'reservationUnit.reservation', 'folioLine', 'resolvedBy'])
            ->findOrFail($posting);
        $detail = PosPostingDetailData::fromModel($model);

        return Inertia::render('finance/pos-postings/show', [
            'posting' => $detail,
            'guests' => $detail->canAttach && $request->user()->can('pos.resolve')
                ? $lookup->find($property, $request->query('q'), null)
                : [],
            'q' => $request->query('q'),
            'currency' => CurrencyData::forProperty($property),
        ]);
    }

    public function resolve(ResolvePosPostingRequest $request, Property $property, int $posting, ResolvePendingPosPosting $resolvePendingPosPosting): RedirectResponse
    {
        $model = PosPosting::query()->where('property_id', $property->id)->findOrFail($posting);
        $result = $resolvePendingPosPosting($request->user(), $model, new ResolvePosPostingData(
            (string) $request->validated('action'),
            $request->validated('reservation_unit_id') === null ? null : (int) $request->validated('reservation_unit_id'),
            $request->validated('folio_id') === null ? null : (int) $request->validated('folio_id'),
            $request->validated('note'),
        ));

        Inertia::flash('toast', $result->isRefused()
            ? ['type' => 'error', 'message' => PosRefusalMessages::message((string) $result->refusal)]
            : ['type' => 'success', 'message' => $request->validated('action') === 'attach' ? __('Receipt posted to the folio.') : __('Posting ignored.')]);

        return to_route('finance.pos-postings.show', ['property' => $property, 'posting' => $model->id]);
    }
}
