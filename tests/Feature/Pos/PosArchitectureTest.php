<?php

use App\Models\FolioLine;

arch('POS actions are invokable classes')
    ->expect('App\Actions\Pos')
    ->classes()
    ->toHaveMethod('__invoke')
    ->ignoring([
        'App\Actions\Pos\MealRedemptionResult',
        'App\Actions\Pos\PosOutletResult',
        'App\Actions\Pos\PosPostingResult',
        'App\Actions\Pos\PosPinResult',
        'App\Actions\Pos\PosActionResult',
        'App\Actions\Pos\PosRefusedException',
        'App\Actions\Pos\Casts',
    ]);

arch('POS services never write through actions')
    ->expect('App\Services\Pos')
    ->not->toUse(['App\Actions']);

arch('POS models never depend on actions, services, DB or Hash')
    ->expect([
        'App\Models\PosApiClient',
        'App\Models\PosCashMovement',
        'App\Models\PosCategory',
        'App\Models\PosComboSlot',
        'App\Models\PosComboSlotOption',
        'App\Models\PosDiningOption',
        'App\Models\PosFloor',
        'App\Models\PosIngredient',
        'App\Models\PosItem',
        'App\Models\PosItemVariant',
        'App\Models\PosKitchenStation',
        'App\Models\PosMenuSchedule',
        'App\Models\PosMenuScheduleTarget',
        'App\Models\PosMenuSnapshot',
        'App\Models\PosModifierGroup',
        'App\Models\PosModifierOption',
        'App\Models\PosOrder',
        'App\Models\PosOrderFolioLink',
        'App\Models\PosOrderLine',
        'App\Models\PosOrderLineModifier',
        'App\Models\PosOrderLineTax',
        'App\Models\PosOrderPayment',
        'App\Models\PosOutlet',
        'App\Models\PosOutletItem',
        'App\Models\PosOutletPaymentMethod',
        'App\Models\PosPosting',
        'App\Models\PosQrSession',
        'App\Models\PosRecipe',
        'App\Models\PosRegister',
        'App\Models\PosShift',
        'App\Models\PosStaffPin',
        'App\Models\PosStockMovement',
        'App\Models\PosStocktake',
        'App\Models\PosStocktakeLine',
        'App\Models\PosTable',
        'App\Models\PosTerminalDevice',
        'App\Models\PosTimeClock',
        'App\Models\PosVoidReason',
    ])
    ->not->toUse(['App\Actions', 'App\Services', 'Illuminate\Support\Facades\DB', 'Illuminate\Support\Facades\Hash']);

arch('POS actions never touch the folio ledger or payment recording directly')
    ->expect('App\Actions\Pos')
    ->not->toUse([FolioLine::class])
    ->ignoring(['App\Actions\Pos\RefundPosOrder']);

arch('POS actions never record payments directly')
    ->expect('App\Actions\Pos')
    ->not->toUse(['App\Actions\Payments\RecordPayment']);

arch('POS middleware are plain classes with a handle method')
    ->expect([
        'App\Http\Middleware\EnsurePosIsActive',
        'App\Http\Middleware\BlockBackOfficeInTerminalMode',
        'App\Http\Middleware\ResolvePosActor',
        'App\Http\Middleware\EnsurePosActorCan',
        'App\Http\Middleware\RequirePosTerminalDevice',
    ])
    ->toHaveMethod('handle');

arch('POS controllers stay thin: no DB facade')
    ->expect('App\Http\Controllers\Pos')
    ->not->toUse('Illuminate\Support\Facades\DB');
