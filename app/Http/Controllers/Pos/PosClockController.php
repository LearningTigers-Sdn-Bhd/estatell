<?php

namespace App\Http\Controllers\Pos;

use App\Actions\Pos\ClockInPosStaff;
use App\Actions\Pos\ClockOutPosStaff;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Pos\Concerns\RespondsToPos;
use App\Http\Requests\Pos\PosClockRequest;
use App\Models\PosOutlet;
use App\Models\Property;
use Illuminate\Http\RedirectResponse;

class PosClockController extends Controller
{
    use RespondsToPos;

    /**
     * Clock the PIN-selected staff member in or out. The PIN was checked when they picked their name.
     */
    public function __invoke(PosClockRequest $request, Property $property, ClockInPosStaff $clockIn, ClockOutPosStaff $clockOut): RedirectResponse
    {
        $actor = $this->actor($request);

        if ($request->validated('action') === 'in') {
            $outlet = PosOutlet::query()->where('property_id', $property->id)->find((int) $request->validated('outlet_id'));
            $this->toast($outlet === null ? 'outlet_invalid' : $clockIn($actor, $property, $outlet)->refusal, __('Clocked in.'));
        } else {
            $this->toast($clockOut($actor, $property)->refusal, __('Clocked out.'));
        }

        return back();
    }
}
