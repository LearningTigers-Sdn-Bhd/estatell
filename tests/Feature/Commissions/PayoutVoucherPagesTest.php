<?php

use App\Enums\CommissionEntryType;
use App\Enums\CommissionService;
use App\Enums\PayoutVoucherStatus;
use App\Models\CommissionEntry;
use App\Models\PayoutVoucher;
use App\Models\Property;
use App\Models\PropertyBankAccount;
use App\Models\Reservation;
use App\Models\User;
use App\Services\Commissions\PayoutVoucherDirectory;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

/**
 * A Draft voucher with a collected entry of RM 540 and a booking engine commission of RM 45, so it pays RM 495.
 */
function draftVoucher(?Property $property = null, string $collected = '540.00'): PayoutVoucher
{
    $property ??= Property::factory()->create();
    $voucher = PayoutVoucher::factory()->create(['property_id' => $property->id]);

    CommissionEntry::factory()->create(['property_id' => $property->id, 'amount' => $collected, 'due_date' => '2026-10-10', 'reason' => 'Opening balance']);
    $commission = CommissionEntry::factory()->create(['property_id' => $property->id, 'amount' => '-45.00', 'due_date' => '2026-10-10', 'reason' => 'Booking engine fee']);
    CommissionEntry::query()->where('property_id', $property->id)->update(['payout_voucher_id' => $voucher->id]);
    $voucher->update(['amount' => (string) ((float) $collected - 45)]);

    return $voucher->refresh();
}

function voucherAdmin(): User
{
    return User::factory()->admin()->withTwoFactor()->create();
}

test('the list shows vouchers and filters them by status', function () {
    $draft = draftVoucher();
    PayoutVoucher::factory()->create(['status' => PayoutVoucherStatus::Paid, 'number' => 'PV-PAID']);

    $this->actingAs(voucherAdmin())
        ->get(route('admin.commissions.vouchers.index', ['filter' => ['status' => 'draft']]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/commissions/vouchers/index')
            ->has('vouchers.data', 1)
            ->where('vouchers.data.0.number', $draft->number)
            ->where('vouchers.data.0.amount', '495.00')
            ->where('vouchers.data.0.status.value', 'draft')
            ->where('cycles.0.label', '16 Sep 2026 – 15 Oct 2026')
            ->where('filters.status', 'draft')
        );
});

test('the voucher page shows the lines, totals and the live bank account of a draft', function () {
    $voucher = draftVoucher();
    PropertyBankAccount::factory()->create(['property_id' => $voucher->property_id, 'bank_name' => 'Maybank', 'account_number' => '5123 4567 4821']);

    $this->actingAs(voucherAdmin())
        ->get(route('admin.commissions.vouchers.show', $voucher))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/commissions/vouchers/show')
            ->has('voucher.lines', 2)
            ->where('voucher.totals.0.label', 'Adjustment')
            ->where('voucher.totals.0.amount', '495.00')
            ->where('voucher.bankAccount.accountNumber', '5123 4567 4821')
            ->where('voucher.canApprove', true)
            ->where('voucher.canMarkPaid', false)
            ->where('voucher.canAdjust', true)
        );
});

test('approving locks the voucher with the bank account and totals', function () {
    $voucher = draftVoucher();
    PropertyBankAccount::factory()->create(['property_id' => $voucher->property_id, 'bank_name' => 'Maybank', 'account_number' => '5123 4567 4821']);
    $admin = voucherAdmin();

    $this->actingAs($admin)
        ->put(route('admin.commissions.vouchers.approve', $voucher))
        ->assertRedirect(route('admin.commissions.vouchers.show', $voucher))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Voucher approved.']);

    $voucher->refresh();
    PropertyBankAccount::query()->update(['account_number' => '9999']);

    expect($voucher->status)->toBe(PayoutVoucherStatus::Approved)
        ->and($voucher->approved_by)->toBe($admin->id)
        ->and($voucher->snapshot['bank_account']['account_number'])->toBe('5123 4567 4821')
        ->and($voucher->snapshot['totals'])->toBe([['key' => 'adjustment', 'amount' => '495.00']])
        ->and(app(PayoutVoucherDirectory::class)->bankAccount($voucher)['accountNumber'])->toBe('5123 4567 4821')
        ->and(Activity::query()->sole()->description)->toBe('Payout voucher approved');
});

test('a positive voucher with no active bank account is not approved', function () {
    $voucher = draftVoucher();
    PropertyBankAccount::factory()->inactive()->create(['property_id' => $voucher->property_id]);

    $this->actingAs(voucherAdmin())
        ->put(route('admin.commissions.vouchers.approve', $voucher))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Add an active bank account for this property before approving.']);

    expect($voucher->refresh()->status)->toBe(PayoutVoucherStatus::Draft)
        ->and(Activity::query()->count())->toBe(0);
});

test('a voucher the property owes is approved without a bank account', function () {
    $voucher = draftVoucher(collected: '-20.00');

    $this->actingAs(voucherAdmin())->put(route('admin.commissions.vouchers.approve', $voucher));

    expect($voucher->refresh()->status)->toBe(PayoutVoucherStatus::Approved)
        ->and($voucher->snapshot['bank_account'])->toBeNull();
});

test('an approved voucher is not approved again', function () {
    $voucher = draftVoucher();
    $voucher->update(['status' => PayoutVoucherStatus::Approved]);

    $this->actingAs(voucherAdmin())
        ->put(route('admin.commissions.vouchers.approve', $voucher))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'This voucher is no longer a draft.']);

    expect(Activity::query()->count())->toBe(0);
});

test('an approved voucher is marked as paid with its transfer', function () {
    $voucher = draftVoucher();
    $voucher->update(['status' => PayoutVoucherStatus::Approved]);

    $this->actingAs(voucherAdmin())
        ->put(route('admin.commissions.vouchers.paid', $voucher), ['paid_on' => '2026-10-14', 'paid_reference' => ' MBB-889 '])
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Voucher marked as paid.']);

    $voucher->refresh();
    expect($voucher->status)->toBe(PayoutVoucherStatus::Paid)
        ->and($voucher->paid_on->toDateString())->toBe('2026-10-14')
        ->and($voucher->paid_reference)->toBe('MBB-889');
});

test('a draft is not marked as paid, and a future transfer date is refused', function () {
    $voucher = draftVoucher();

    $this->actingAs(voucherAdmin())
        ->put(route('admin.commissions.vouchers.paid', $voucher), ['paid_on' => '2026-10-14', 'paid_reference' => 'MBB-889'])
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Only an approved voucher can be marked as paid.']);

    $voucher->update(['status' => PayoutVoucherStatus::Approved]);
    $this->put(route('admin.commissions.vouchers.paid', $voucher), ['paid_on' => '2026-10-15', 'paid_reference' => 'MBB-889'])
        ->assertSessionHasErrors(['paid_on' => 'The transfer date cannot be in the future.']);

    expect($voucher->refresh()->status)->toBe(PayoutVoucherStatus::Approved);
});

test('an adjustment is linked to the draft and moves its total, once per form', function () {
    $voucher = draftVoucher();
    $payload = ['amount' => '-25', 'reason' => 'Late fee', 'idempotency_key' => '9b2f6c1e-1f1a-4c8e-9a51-2d1f0b8f1a11'];

    $this->actingAs(voucherAdmin())
        ->post(route('admin.commissions.vouchers.adjustments.store', $voucher), $payload)
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Adjustment added.']);
    $this->post(route('admin.commissions.vouchers.adjustments.store', $voucher), $payload);

    $adjustment = CommissionEntry::query()->where('reason', 'Late fee')->sole();
    expect($adjustment->type)->toBe(CommissionEntryType::Adjustment)
        ->and($adjustment->amount)->toBe('-25.00')
        ->and($adjustment->payout_voucher_id)->toBe($voucher->id)
        ->and($adjustment->due_date->toDateString())->toBe('2026-10-15')
        ->and($voucher->refresh()->amount)->toBe('470.00')
        ->and(Activity::query()->count())->toBe(1);
});

test('an adjustment is refused on an approved voucher or for zero', function () {
    $voucher = draftVoucher();
    $key = '9b2f6c1e-1f1a-4c8e-9a51-2d1f0b8f1a11';

    $this->actingAs(voucherAdmin())
        ->post(route('admin.commissions.vouchers.adjustments.store', $voucher), ['amount' => '0.00', 'reason' => 'Nothing', 'idempotency_key' => $key])
        ->assertSessionHasErrors(['amount' => 'Enter an amount other than zero.']);

    $voucher->update(['status' => PayoutVoucherStatus::Approved]);
    $this->post(route('admin.commissions.vouchers.adjustments.store', $voucher), ['amount' => '10', 'reason' => 'Late', 'idempotency_key' => $key])
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Only a draft voucher can be adjusted.']);

    expect(CommissionEntry::query()->count())->toBe(2);
});

test('voucher pages are for admins only, and writes need two-factor', function () {
    $voucher = draftVoucher();

    $this->actingAs(User::factory()->create())->get(route('admin.commissions.vouchers.index'))->assertNotFound();
    $this->get(route('admin.commissions.vouchers.show', $voucher))->assertNotFound();
    $this->get(route('admin.commissions.vouchers.pdf', $voucher))->assertNotFound();

    config(['estatell.support_require_two_factor' => true]);
    $this->actingAs(User::factory()->admin()->create())->put(route('admin.commissions.vouchers.approve', $voucher))->assertRedirect();

    expect($voucher->refresh()->status)->toBe(PayoutVoucherStatus::Draft);
});

test('the PDF opens and shows only the last digits of the account number', function () {
    $voucher = draftVoucher();
    PropertyBankAccount::factory()->create(['property_id' => $voucher->property_id, 'account_number' => '5123 4567 4821']);

    $this->actingAs(voucherAdmin())
        ->get(route('admin.commissions.vouchers.pdf', $voucher))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');

    $html = view('pdf.payout-voucher', ['voucher' => app(PayoutVoucherDirectory::class)->detail($voucher)])->render();

    expect($html)->toContain('•••• 4821')
        ->and($html)->toContain($voucher->number)
        ->and($html)->not->toContain('5123 4567');
});

test('a channel manager commission line reads as such', function () {
    $voucher = draftVoucher();
    $entry = CommissionEntry::factory()->create([
        'property_id' => $voucher->property_id,
        'type' => CommissionEntryType::Commission,
        'reservation_id' => Reservation::factory()->create(['property_id' => $voucher->property_id])->id,
        'service' => CommissionService::ChannelManager,
        'base_amount' => '100.00',
        'rate' => '3.00',
        'amount' => '-3.00',
        'reason' => null,
    ]);
    CommissionEntry::query()->whereKey($entry->id)->update(['payout_voucher_id' => $voucher->id]);

    $detail = app(PayoutVoucherDirectory::class)->detail($voucher->refresh());

    expect(collect($detail->totals)->pluck('label')->all())->toBe(['Channel manager commission', 'Adjustment']);
});
