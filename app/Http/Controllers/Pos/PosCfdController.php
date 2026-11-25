<?php

namespace App\Http\Controllers\Pos;

use App\Actions\Pos\UpdateRegisterDisplayState;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\UpdatePosCfdStateRequest;
use App\Models\PosRegister;
use App\Models\Property;
use App\Services\Pos\CustomerDisplay;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The read-only customer-facing display of one register, the version it polls, and the endpoint the cashier terminal uses
 * to tell it which check is on screen. See docs/34-native-pos/10-customer-facing-display.md.
 */
class PosCfdController extends Controller
{
    public function show(Property $property, int $register, CustomerDisplay $display): Response
    {
        $model = $this->register($property, $register);

        return Inertia::render('pos/cfd', [
            'registerId' => $model->id,
            'version' => $display->version($model->id),
            'state' => $display->stateFor($property, $model),
        ]);
    }

    public function version(Property $property, int $register, CustomerDisplay $display): JsonResponse
    {
        return response()->json(['version' => $display->version($this->register($property, $register)->id)]);
    }

    public function state(UpdatePosCfdStateRequest $request, Property $property, int $register, UpdateRegisterDisplayState $update, CustomerDisplay $display): JsonResponse
    {
        $model = $this->register($property, $register);
        $orderId = $request->validated('order_id');
        $update($property, $model, $orderId === null ? null : (int) $orderId, $request->boolean('checkout_active'));

        return response()->json(['version' => $display->version($model->id)]);
    }

    private function register(Property $property, int $register): PosRegister
    {
        return PosRegister::query()->where('property_id', $property->id)->with('outlet')->findOrFail($register);
    }
}
