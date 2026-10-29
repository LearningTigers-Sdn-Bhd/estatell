<?php

use App\Actions\Payments\RecordPayment;
use App\Data\Payments\NewPaymentData;
use App\Enums\PaymentMethodType;
use App\Enums\PaymentPurpose;
use App\Models\PaymentMethod;
use Illuminate\Support\Str;
use Pest\Browser\Api\AwaitableWebpage;

beforeEach(function () {
    $this->signInAsScenariosOwner();
});

/**
 * S11 departs today owing MYR 396.00. A MYR 500.00 advance and a MYR 100.00 security deposit put its folio in credit, so
 * it leaves Outstanding balance and shows in Deposit liability.
 */
function balancesTakeDeposits(): void
{
    $reservation = test()->scenario('S11');
    $cash = PaymentMethod::query()->where('property_id', $reservation->property_id)->where('type', PaymentMethodType::Cash)->where('active', true)->firstOrFail();

    foreach ([[PaymentPurpose::Advance, '500.00'], [PaymentPurpose::SecurityDeposit, '100.00']] as [$purpose, $amount]) {
        app(RecordPayment::class)(auth()->user(), $reservation, null, $reservation->units()->firstOrFail(), new NewPaymentData($cash->id, $purpose, $amount, null, (string) Str::uuid()));
    }
}

/**
 * Open the report with the sidebar folded away, so every column fits on the 1440 px screen.
 */
function balancesOpen(string $url): AwaitableWebpage
{
    return test()->open($url, height: 1000)
        ->wait(1)
        ->click('[data-sidebar="trigger"]')
        ->wait(1);
}

test('outstanding balance', function () {
    balancesTakeDeposits();

    $page = balancesOpen(route('reports.outstanding-balance', ['property' => $this->property, 'date' => '2026-10-14']));

    $this->shoot($page, 'outstanding-balance',
        badges: ['Today', 'Balance', 'Days since departure'],
        badgesFromLeft: 0,
    );
});

test('deposit liability', function () {
    balancesTakeDeposits();

    $page = balancesOpen(route('reports.deposit-liability', ['property' => $this->property, 'date' => '2026-10-14']));

    $this->shoot($page, 'deposit-liability',
        badges: ['Security deposit', 'Held'],
        badgesFromLeft: 0,
    );
});
