<?php

namespace App\Http\Controllers\Pos;

use App\Actions\Pos\EnterPosTerminalMode;
use App\Actions\Pos\ExitPosTerminalMode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\ExitPosTerminalModeRequest;
use App\Models\Property;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PosTerminalModeController extends Controller
{
    public function enter(Request $request, Property $property, EnterPosTerminalMode $enterPosTerminalMode): RedirectResponse
    {
        $enterPosTerminalMode($request->session());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Terminal Mode is on. Sign in with your PIN.')]);

        return back();
    }

    public function exit(ExitPosTerminalModeRequest $request, Property $property, ExitPosTerminalMode $exitPosTerminalMode): RedirectResponse
    {
        if (! $exitPosTerminalMode($request->session(), $request->user(), (string) $request->validated('password'))) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('That password is not right.')]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Terminal Mode is off.')]);

        return to_route('dashboard', $property);
    }
}
