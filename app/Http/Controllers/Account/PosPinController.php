<?php

namespace App\Http\Controllers\Account;

use App\Actions\Pos\SetPosStaffPin;
use App\Http\Controllers\Controller;
use App\Http\Requests\Account\SetPosPinRequest;
use App\Services\Pos\PosRefusalMessages;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class PosPinController extends Controller
{
    public function update(SetPosPinRequest $request, SetPosStaffPin $setPosStaffPin): RedirectResponse
    {
        $refusal = $setPosStaffPin($request->user(), (string) $request->validated('pin'));

        Inertia::flash('toast', $refusal === null
            ? ['type' => 'success', 'message' => __('POS PIN saved.')]
            : ['type' => 'error', 'message' => PosRefusalMessages::message($refusal)]);

        return back();
    }
}
