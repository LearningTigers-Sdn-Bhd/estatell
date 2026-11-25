<?php

namespace App\Http\Controllers\Pos;

use App\Actions\Pos\BeginCloseShift;
use App\Actions\Pos\CancelCloseShift;
use App\Actions\Pos\FinalizeCloseShift;
use App\Actions\Pos\OpenPosShift;
use App\Actions\Pos\RecordCashMovement;
use App\Actions\Pos\ResolvePosApproval;
use App\Actions\Pos\SubmitBlindCount;
use App\Enums\PermissionEnum;
use App\Enums\PosCashMovementKind;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Pos\Concerns\RespondsToPos;
use App\Http\Requests\Pos\CloseShiftRequest;
use App\Http\Requests\Pos\OpenPosShiftRequest;
use App\Http\Requests\Pos\RecordCashMovementRequest;
use App\Http\Requests\Pos\SubmitBlindCountRequest;
use App\Models\PosRegister;
use App\Models\PosShift;
use App\Models\Property;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PosShiftController extends Controller
{
    use RespondsToPos;

    public function open(OpenPosShiftRequest $request, Property $property, OpenPosShift $openPosShift): RedirectResponse
    {
        $register = PosRegister::query()->where('property_id', $property->id)->findOrFail((int) $request->validated('register_id'));
        $result = $openPosShift($this->actor($request), $property, $register, $request->validated('opening_cash'));

        if (! $this->toast($result->refusal, __('Shift opened.'))) {
            return back();
        }

        return to_route('pos.terminal', ['property' => $property, 'outlet' => $register->pos_outlet_id, 'register' => $register->id]);
    }

    public function movement(RecordCashMovementRequest $request, Property $property, int $shift, RecordCashMovement $recordCashMovement, ResolvePosApproval $resolveApproval): RedirectResponse
    {
        $model = $this->shift($property, $shift);
        $actor = $this->actor($request);
        $kind = PosCashMovementKind::from((string) $request->validated('kind'));
        $approval = $kind === PosCashMovementKind::NoSaleOpen
            ? $resolveApproval($request, $property, $model->outlet, $actor, PermissionEnum::PosVoid, 'no sale', ['shift' => $model->id])
            : ['approver' => null, 'error' => null];

        if ($approval['error'] !== null) {
            $this->error($approval['error']);

            return back();
        }

        $result = $recordCashMovement($actor, $property, $model, $kind, (string) ($request->validated('amount') ?? '0.00'), (string) $request->validated('reason'), (string) $request->validated('idempotency_key'), $request->validated('counter_transaction_code_id') === null ? null : (int) $request->validated('counter_transaction_code_id'), $approval['approver']);
        $this->toast($result->refusal, __('Cash movement saved.'));

        return back();
    }

    public function beginClose(Request $request, Property $property, int $shift, BeginCloseShift $beginCloseShift): RedirectResponse
    {
        $this->toast($beginCloseShift($this->actor($request), $property, $this->shift($property, $shift))->refusal, __('Register is closing. Count the cash.'));

        return back();
    }

    public function cancelClose(Request $request, Property $property, int $shift, CancelCloseShift $cancelCloseShift): RedirectResponse
    {
        $this->toast($cancelCloseShift($this->actor($request), $property, $this->shift($property, $shift))->refusal, __('Trading resumed.'));

        return back();
    }

    public function count(SubmitBlindCountRequest $request, Property $property, int $shift, SubmitBlindCount $submitBlindCount, ResolvePosApproval $resolveApproval): RedirectResponse
    {
        $model = $this->shift($property, $shift);
        $actor = $this->actor($request);
        $approval = $resolveApproval($request, $property, $model->outlet, $actor, PermissionEnum::PosReports, 'recount', ['shift' => $model->id]);

        if ($approval['error'] !== null) {
            $this->error($approval['error']);

            return back();
        }

        $this->toast($submitBlindCount($actor, $property, $model, (string) $request->validated('counted_cash'), $approval['approver'])->refusal, __('Count saved.'));

        return back();
    }

    public function close(CloseShiftRequest $request, Property $property, int $shift, FinalizeCloseShift $finalizeCloseShift): RedirectResponse
    {
        $model = $this->shift($property, $shift);
        $result = $finalizeCloseShift($this->actor($request), $property, $model, $request->validated('note'));

        if (! $this->toast($result->refusal, __('Shift closed.'))) {
            return back();
        }

        return to_route('pos.index', $property);
    }

    private function shift(Property $property, int $id): PosShift
    {
        return PosShift::query()->where('property_id', $property->id)->with('outlet')->findOrFail($id);
    }
}
