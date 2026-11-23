<?php

use App\Actions\Pos\SyncLoyverseGuest;
use App\Enums\PosPostingStatus;
use App\Enums\PosPostingType;
use App\Enums\UnitStatus;
use App\Jobs\RemoveLoyverseGuestJob;
use App\Jobs\SyncLoyverseGuestJob;
use App\Models\PosGuestLink;
use App\Models\PosPosting;
use App\Models\UnitNight;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

require_once __DIR__.'/../Support/LoyverseSetup.php';

it('voids a charge when Loyverse reports the refund amount as negative', function () {
    $setup = loyverseSetup();
    loyverseLink($setup);
    loyverseWebhook([loyverseReceipt()]);

    loyverseWebhook([loyverseReceipt(['receipt_number' => '2-1010', 'receipt_type' => 'REFUND', 'refund_for' => '2-1008', 'payments' => [['payment_type_id' => 'pt-room', 'money_amount' => -85.5]]])]);

    expect(PosPosting::query()->where('posting_type', PosPostingType::Charge)->sole()->status)->toBe(PosPostingStatus::Reversed);
});

it('voids a posted charge when the cancelled receipt carries no payments', function () {
    $setup = loyverseSetup();
    loyverseLink($setup);
    loyverseWebhook([loyverseReceipt()]);

    loyverseWebhook([loyverseReceipt(['cancelled_at' => '2026-10-14T05:00:00.000Z', 'payments' => []])]);

    expect(PosPosting::query()->where('posting_type', PosPostingType::Charge)->sole()->status)->toBe(PosPostingStatus::Reversed);
});

it('does not invent a void for a cancelled walk-in sale it never posted', function () {
    $setup = loyverseSetup();
    loyverseLink($setup);

    loyverseWebhook([loyverseReceipt(['cancelled_at' => '2026-10-14T05:00:00.000Z', 'payments' => [['payment_type_id' => 'pt-cash', 'money_amount' => 10.0]]])]);

    expect(PosPosting::query()->count())->toBe(0);
});

it('serializes the sync and remove jobs of one guest, so two workers never create two customers', function () {
    $key = fn (object $job): ?string => collect($job->middleware())->first(fn (object $middleware): bool => $middleware instanceof WithoutOverlapping)?->key;

    expect($key(new SyncLoyverseGuestJob(1, 2)))->not->toBeNull()
        ->and($key(new SyncLoyverseGuestJob(1, 2)))->toBe($key(new RemoveLoyverseGuestJob(1, 2)))
        ->and($key(new SyncLoyverseGuestJob(1, 2)))->not->toBe($key(new SyncLoyverseGuestJob(1, 3)));
});

it('creates the customer again when Loyverse no longer has the linked one', function () {
    $setup = loyverseSetup();
    loyverseRoom($setup);
    loyverseLink($setup, 'cust-gone', 'Room 999 - Guest');
    Http::fake(['api.loyverse.com/v1.0/customers' => Http::sequence()->push([], 404)->push(['id' => 'cust-new'], 200)]);

    app(SyncLoyverseGuest::class)($setup['connection'], $setup['unit']->id);

    expect(PosGuestLink::query()->sole()->external_customer_id)->toBe('cust-new');
});

it('removes the links of guests who left while Loyverse was disconnected, when staff sync guests again', function () {
    $setup = loyverseSetup();
    $left = loyverseOtherUnit($setup);
    $left->forceFill(['status' => UnitStatus::CheckedOut, 'checked_out_at' => now()])->save();
    $link = new PosGuestLink;
    $link->property_id = $setup['property']->id;
    $link->pos_connection_id = $setup['connection']->id;
    $link->reservation_unit_id = $left->id;
    $link->fill(['external_customer_id' => 'cust-left', 'synced_label' => 'Room 5 - Left', 'last_synced_at' => now()])->save();
    Queue::fake();

    $this->actingAs($setup['actor'])->post(route('settings.pos-integrations.loyverse.sync-guests', $setup['property']->code))->assertRedirect();

    Queue::assertPushed(RemoveLoyverseGuestJob::class, fn (RemoveLoyverseGuestJob $job): bool => $job->reservationUnitId === $left->id);
});

it('syncs a checked-in guest whose last night was before the business date, such as a departure-morning or overdue guest', function () {
    $setup = loyverseSetup();
    loyverseRoom($setup, '301');
    UnitNight::query()->where('reservation_unit_id', $setup['unit']->id)->update(['date' => '2026-10-13']);
    Http::fake(['api.loyverse.com/v1.0/customers' => Http::response(['id' => 'cust-late'], 200)]);

    app(SyncLoyverseGuest::class)($setup['connection'], $setup['unit']->id);

    expect(PosGuestLink::query()->sole())
        ->external_customer_id->toBe('cust-late')
        ->synced_label->toStartWith('Room 301 - ');
});

it('gives existing properties the POS permissions when the migration runs', function () {
    Permission::query()->where('name', 'like', 'pos.%')->orWhere('name', 'meals.redeem')->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    (require database_path('migrations/2026_11_23_120000_add_pos_permissions.php'))->up();

    expect(Permission::query()->whereIn('name', ['pos.view', 'pos.manage', 'pos.resolve', 'meals.redeem'])->count())->toBe(4);
});
