<?php

use App\Features\NativePos;
use App\Models\BusinessDate;
use App\Models\PosOutlet;
use App\Models\PosStaffPin;
use App\Models\PosTerminalDevice;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;

/**
 * A live property with Native POS on, one outlet, a manager (every POS permission, PIN 123456), a cashier (sell and shift
 * only, PIN 1234) and a registered terminal device whose cookie token is returned as `token`.
 *
 * @return array{property: Property, outlet: PosOutlet, manager: User, cashier: User, device: PosTerminalDevice, token: string}
 */
function nativePosSetup(): array
{
    $property = Property::factory()->live()->create();
    PropertySetting::factory()->for($property)->create();
    BusinessDate::factory()->create(['property_id' => $property->id]);
    Feature::for($property)->activate(NativePos::class);

    $outlet = PosOutlet::factory()->create(['property_id' => $property->id]);
    $manager = User::factory()->for($property)->withPermissions(['pos.sell', 'pos.shift', 'pos.void', 'pos.refund', 'pos.discount', 'pos.manage', 'pos.reports'])->create();
    $cashier = User::factory()->for($property)->withPermissions(['pos.sell', 'pos.shift'])->create();

    PosStaffPin::factory()->withPin('123456')->create(['property_id' => $property->id, 'user_id' => $manager->id]);
    PosStaffPin::factory()->withPin('1234')->create(['property_id' => $property->id, 'user_id' => $cashier->id]);

    $token = 'device-token-'.Str::random(12);
    $device = PosTerminalDevice::factory()->withToken($token)->create(['property_id' => $property->id, 'pos_outlet_id' => $outlet->id]);

    return ['property' => $property, 'outlet' => $outlet, 'manager' => $manager, 'cashier' => $cashier, 'device' => $device, 'token' => $token];
}
