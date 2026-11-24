<?php

use App\Models\FolioLine;

arch('Native POS actions are invokable classes')
    ->expect('App\Actions\Pos')
    ->classes()
    ->toHaveMethod('__invoke')
    ->ignoring([
        'App\Actions\Pos\MealRedemptionResult',
        'App\Actions\Pos\PosOutletResult',
        'App\Actions\Pos\PosPostingResult',
        'App\Actions\Pos\PosPinResult',
        'App\Actions\Pos\Casts',
    ]);

arch('Native POS services never write through Eloquent models or actions')
    ->expect('App\Services\Pos')
    ->not->toUse(['App\Actions']);

arch('POS models hold relations, casts and scopes only')
    ->expect(['App\Models\PosStaffPin', 'App\Models\PosTerminalDevice'])
    ->not->toUse(['App\Actions', 'App\Services', 'Illuminate\Support\Facades\DB', 'Illuminate\Support\Facades\Hash']);

arch('PIN and terminal actions never touch the folio ledger')
    ->expect([
        'App\Actions\Pos\VerifyPosPin',
        'App\Actions\Pos\VerifyPosApproval',
        'App\Actions\Pos\SetPosStaffPin',
        'App\Actions\Pos\RevokePosStaffPin',
        'App\Actions\Pos\RegisterPosTerminalDevice',
        'App\Actions\Pos\RevokePosTerminalDevice',
        'App\Actions\Pos\SwitchPosActor',
    ])
    ->not->toUse([FolioLine::class, 'App\Actions\Payments\RecordPayment']);

arch('Native POS middleware are plain classes with a handle method')
    ->expect([
        'App\Http\Middleware\EnsureNativePosIsActive',
        'App\Http\Middleware\EnsurePosAnyIsActive',
        'App\Http\Middleware\BlockBackOfficeInTerminalMode',
        'App\Http\Middleware\ResolvePosActor',
        'App\Http\Middleware\EnsurePosActorCan',
        'App\Http\Middleware\RequirePosTerminalDevice',
    ])
    ->toHaveMethod('handle');

arch('Native POS controllers stay thin: no models written, no DB facade')
    ->expect(['App\Http\Controllers\Pos\PosPinController', 'App\Http\Controllers\Pos\PosTerminalModeController', 'App\Http\Controllers\Pos\PosStaffPinController', 'App\Http\Controllers\Pos\PosTerminalDeviceController', 'App\Http\Controllers\Account\PosPinController'])
    ->not->toUse('Illuminate\Support\Facades\DB');
