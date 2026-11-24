<?php

namespace App\Http\Controllers\Pos;

use App\Actions\Pos\PosActionResult;
use App\Actions\Pos\SavePosVoidReason;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Pos\Concerns\RespondsToPos;
use App\Http\Requests\Pos\SavePosVoidReasonRequest;
use App\Models\PosOutlet;
use App\Models\PosStaffPin;
use App\Models\PosTerminalDevice;
use App\Models\PosVoidReason;
use App\Models\Property;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Staff PIN status, registered terminals and void reasons: the people and tablets that may trade.
 */
class PosStaffSettingsController extends Controller
{
    use RespondsToPos;

    public function index(Property $property): Response
    {
        $pins = PosStaffPin::query()->where('property_id', $property->id)->get()->keyBy('user_id');

        return Inertia::render('settings/pos/staff-pins', [
            'staff' => User::query()->where('property_id', $property->id)->whereNull('deactivated_at')->orderBy('name')->get(['id', 'name'])->map(fn (User $user): array => ['id' => $user->id, 'name' => $user->name, 'hasPin' => (bool) $pins->get($user->id)?->active])->all(),
            'devices' => PosTerminalDevice::query()->where('property_id', $property->id)->with('outlet:id,name')->orderByDesc('id')->get()->map(fn (PosTerminalDevice $device): array => ['id' => $device->id, 'name' => $device->name, 'outlet' => $device->outlet->name, 'revoked' => $device->revoked_at !== null, 'lastSeenAt' => $device->last_seen_at?->toIso8601String()])->all(),
            'outlets' => PosOutlet::query()->where('property_id', $property->id)->where('native_pos_enabled', true)->orderBy('name')->get(['id', 'name'])->map(fn (PosOutlet $outlet): array => ['value' => (string) $outlet->id, 'label' => $outlet->name])->all(),
            'voidReasons' => PosVoidReason::query()->where('property_id', $property->id)->orderBy('sort_order')->orderBy('label')->get(['id', 'label', 'requires_manager_pin', 'default_return_stock', 'active'])->all(),
        ]);
    }

    public function storeVoidReason(SavePosVoidReasonRequest $request, Property $property, SavePosVoidReason $savePosVoidReason): RedirectResponse
    {
        $this->toast($this->save($request, $property, $savePosVoidReason, null)->refusal, __('Void reason added.'));

        return back();
    }

    public function updateVoidReason(SavePosVoidReasonRequest $request, Property $property, int $reason, SavePosVoidReason $savePosVoidReason): RedirectResponse
    {
        $this->toast($this->save($request, $property, $savePosVoidReason, PosVoidReason::query()->where('property_id', $property->id)->findOrFail($reason))->refusal, __('Void reason saved.'));

        return back();
    }

    private function save(SavePosVoidReasonRequest $request, Property $property, SavePosVoidReason $savePosVoidReason, ?PosVoidReason $reason): PosActionResult
    {
        return $savePosVoidReason($request->user(), $property, (string) $request->validated('label'), $request->boolean('requires_manager_pin'), $request->boolean('default_return_stock'), $request->boolean('active'), (int) ($request->validated('sort_order') ?? 0), $reason);
    }
}
