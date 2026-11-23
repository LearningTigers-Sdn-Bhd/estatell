<?php

namespace App\Http\Controllers\Api\Pos;

use App\Actions\Pos\PostPosCharge;
use App\Actions\Pos\VoidPosCharge;
use App\Data\Pos\PosChargeData;
use App\Data\Pos\PosVoidData;
use App\Enums\PosPostingStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\PostRoomChargeRequest;
use App\Http\Requests\Pos\VoidRoomChargeRequest;
use App\Http\Responses\ApiError;
use App\Models\PosApiClient;
use App\Models\PosOutlet;
use App\Models\PosPosting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RoomChargeController extends Controller
{
    public function store(PostRoomChargeRequest $request, PostPosCharge $postPosCharge): JsonResponse
    {
        $outlet = $this->outlet($request, (string) $request->validated('outlet_code'));

        if ($outlet === null) {
            return $this->outletNotFound();
        }

        $result = $postPosCharge($outlet, new PosChargeData(
            (string) $request->validated('external_receipt_id'),
            (string) $request->validated('external_receipt_number'),
            (string) $request->validated('amount'),
            $request->validated('reservation_unit_id') === null ? null : (int) $request->validated('reservation_unit_id'),
            (array) ($request->validated('raw_payload') ?? []),
            serviceDate: $request->validated('service_date'),
        ));

        return $this->respond($outlet, $result->posting, $result->replayed ? 200 : 201);
    }

    public function void(VoidRoomChargeRequest $request, string $externalReceiptId, VoidPosCharge $voidPosCharge): JsonResponse
    {
        $outlet = $this->outlet($request, (string) $request->validated('outlet_code'));

        if ($outlet === null) {
            return $this->outletNotFound();
        }

        $result = $voidPosCharge($outlet, new PosVoidData(
            (string) $request->validated('void_receipt_id'),
            $externalReceiptId,
            $request->validated('amount'),
            $request->validated('reason'),
            (array) ($request->validated('raw_payload') ?? []),
        ));

        return $this->respond($outlet, $result->posting, 200);
    }

    /**
     * A posted or reversed posting answers with `$okStatus`; one waiting for front desk answers `202`, so the POS knows
     * the receipt was captured and not lost.
     */
    private function respond(PosOutlet $outlet, PosPosting $posting, int $okStatus): JsonResponse
    {
        $body = [
            'posting_id' => $posting->id,
            'status' => $posting->status->value,
            'folio_line_id' => $posting->folio_line_id,
            'description' => $outlet->name.' — Receipt #'.$posting->external_receipt_number,
            'amount' => $posting->amount,
        ];

        if ($posting->status === PosPostingStatus::PendingReview) {
            return response()->json([...$body, 'failure_reason' => $posting->failure_reason], 202);
        }

        return response()->json($body, $okStatus);
    }

    private function outlet(Request $request, string $code): ?PosOutlet
    {
        /** @var PosApiClient $client */
        $client = $request->attributes->get('pos_client');

        return PosOutlet::query()
            ->where('property_id', $client->property_id)
            ->where('code', mb_strtoupper(trim($code)))
            ->active()
            ->first();
    }

    private function outletNotFound(): JsonResponse
    {
        return ApiError::response('outlet_not_found', __('This outlet does not exist or is not active.'), 404);
    }
}
