<?php

use App\Enums\PosOrderStatus;
use App\Models\PosOrder;
use App\Models\PosRegister;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../Support/PosShiftSetup.php';

it('returns register shift badges, opener name, open check count and currency on pos index', function () {
    $s = posShiftSetup();
    $s['outlet']->refresh();
    $shift = posOpenShift($s);
    $closed = PosRegister::factory()->create([
        'property_id' => $s['property']->id,
        'pos_outlet_id' => $s['outlet']->id,
        'name' => 'Bar Counter',
    ]);
    PosOrder::factory()->count(2)->create([
        'property_id' => $s['property']->id,
        'pos_outlet_id' => $s['outlet']->id,
        'pos_shift_id' => $shift->id,
        'status' => PosOrderStatus::Open,
    ]);

    $this->actingAs($s['manager'])
        ->get(route('pos.index', $s['property']->code))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('pos/index')
            ->has('currency.code')
            ->where('canShift', true)
            ->where('canManage', true)
            ->has('outlets', 1)
            ->where('outlets.0.operationMode', $s['outlet']->operation_mode->label())
            ->has('outlets.0.registers', 2)
            ->where('outlets.0.registers', function ($registers) use ($s, $shift, $closed): bool {
                $byId = collect($registers)->keyBy('id');
                $open = $byId->get($s['register']->id);
                $idle = $byId->get($closed->id);

                return $open !== null
                    && $open['shiftNumber'] === $shift->shift_number
                    && $open['shiftStatus']['value'] === 'open'
                    && $open['shiftStatus']['tone'] === 'success'
                    && $open['openedBy'] === $s['manager']->name
                    && $open['openOrderCount'] === 2
                    && $idle !== null
                    && $idle['shiftNumber'] === null
                    && $idle['shiftStatus']['value'] === 'closed'
                    && $idle['shiftStatus']['tone'] === 'neutral'
                    && $idle['openOrderCount'] === 0;
            })
        );
});
