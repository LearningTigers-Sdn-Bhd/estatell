<?php

use App\Enums\PosConnectionStatus;
use App\Enums\PosPostingStatus;
use App\Enums\PosPostingType;
use App\Features\PosIntegration;
use App\Jobs\ProcessLoyverseWebhookJob;
use App\Models\FolioLine;
use App\Models\PosPosting;
use App\Services\Folio\FolioBalance;
use Illuminate\Support\Facades\Queue;
use Laravel\Pennant\Feature;

require_once __DIR__.'/../Support/LoyverseSetup.php';

it('rejects a webhook with no signature, a wrong one or an empty secret', function () {
    $setup = loyverseSetup();
    loyverseLink($setup);

    loyverseWebhook([loyverseReceipt()], signature: '')->assertUnauthorized();
    loyverseWebhook([loyverseReceipt()], signature: str_repeat('a', 40))->assertUnauthorized();
    loyverseWebhook([loyverseReceipt()], signature: hash_hmac('sha1', 'other body', 'sec'))->assertUnauthorized();

    config(['services.loyverse.client_secret' => '']);
    loyverseWebhook([loyverseReceipt()])->assertUnauthorized();

    expect(PosPosting::query()->count())->toBe(0);
});

it('answers 200 at once and queues the work, but only for receipts.update', function () {
    loyverseSetup();
    Queue::fake();

    loyverseWebhook([loyverseReceipt()])->assertOk();
    loyverseWebhook([], type: 'customers.update')->assertOk();

    Queue::assertPushed(ProcessLoyverseWebhookJob::class, 1);
});

it('accepts an uppercase hex signature', function () {
    $setup = loyverseSetup();
    loyverseLink($setup);
    $body = json_encode(['merchant_id' => 'merch-1', 'type' => 'receipts.update', 'receipts' => [loyverseReceipt()]], JSON_THROW_ON_ERROR);

    $this->call('POST', '/webhooks/loyverse', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_LOYVERSE_SIGNATURE' => strtoupper(hash_hmac('sha1', $body, 'sec'))], $body)->assertOk();

    expect(PosPosting::query()->count())->toBe(1);
});

it('posts only the Room Charge part of a split payment to the linked guest folio', function () {
    $setup = loyverseSetup();
    loyverseLink($setup);

    loyverseWebhook([loyverseReceipt()])->assertOk();
    $posting = PosPosting::query()->sole();
    $line = FolioLine::query()->findOrFail($posting->folio_line_id);

    expect($posting->status)->toBe(PosPostingStatus::Posted)
        ->and($posting->amount)->toBe('85.50')
        ->and($posting->external_receipt_id)->toBe('2-1008')
        ->and($posting->pos_connection_id)->toBe($setup['connection']->id)
        ->and($posting->reservation_unit_id)->toBe($setup['unit']->id)
        ->and($posting->raw_payload['payments'][0]['name'])->toBe('Cash')
        ->and($line->description)->toBe('Main Restaurant — Receipt #2-1008')
        ->and(app(FolioBalance::class)->forFolio($line->folio))->toBe('85.50');
});

it('posts the same receipt once when Loyverse sends it again', function () {
    $setup = loyverseSetup();
    loyverseLink($setup);

    loyverseWebhook([loyverseReceipt()]);
    loyverseWebhook([loyverseReceipt()]);
    loyverseWebhook([loyverseReceipt(), loyverseReceipt()]);

    expect(PosPosting::query()->count())->toBe(1)
        ->and(FolioLine::query()->whereNull('parent_line_id')->count())->toBe(1);
});

it('keeps a sale with no linked guest for review, with a hint and the whole receipt', function () {
    loyverseSetup();

    loyverseWebhook([loyverseReceipt(['customer_id' => null, 'note' => 'room 301 please'])]);
    loyverseWebhook([loyverseReceipt(['receipt_number' => '2-1009', 'customer_id' => 'cust-unknown'])]);
    $postings = PosPosting::query()->orderBy('id')->get();

    expect($postings)->toHaveCount(2)
        ->and($postings[0]->status)->toBe(PosPostingStatus::PendingReview)
        ->and($postings[0]->failure_reason)->toBe('No linked in-house guest on receipt')
        ->and($postings[0]->raw_payload['note'])->toBe('room 301 please')
        ->and($postings[1]->failure_reason)->toBe('No linked in-house guest on receipt')
        ->and(FolioLine::query()->count())->toBe(0);
});

it('ignores walk-in sales, unmapped stores, inactive mappings and unknown merchants', function () {
    $setup = loyverseSetup();
    loyverseLink($setup);
    $cashOnly = loyverseReceipt(['receipt_number' => '2-2001', 'payments' => [['payment_type_id' => 'pt-cash', 'money_amount' => 100.0]]]);

    loyverseWebhook([$cashOnly]);
    loyverseWebhook([loyverseReceipt(['receipt_number' => '2-2002', 'store_id' => 'store-other'])]);
    loyverseWebhook([loyverseReceipt(['receipt_number' => '2-2003'])], merchantId: 'someone-else');
    $setup['mapping']->forceFill(['active' => false])->save();
    loyverseWebhook([loyverseReceipt(['receipt_number' => '2-2004'])]);

    expect(PosPosting::query()->count())->toBe(0);
});

it('ignores receipts while the connection is disconnected or POS integration is off', function () {
    $setup = loyverseSetup();
    loyverseLink($setup);

    Feature::for($setup['property'])->deactivate(PosIntegration::class);
    loyverseWebhook([loyverseReceipt()]);

    Feature::for($setup['property'])->activate(PosIntegration::class);
    $setup['connection']->forceFill(['status' => PosConnectionStatus::Disconnected])->save();
    loyverseWebhook([loyverseReceipt(['receipt_number' => '2-3001'])]);

    expect(PosPosting::query()->count())->toBe(0);
});

it('reverses a charge when Loyverse sends a full refund of it', function () {
    $setup = loyverseSetup();
    loyverseLink($setup);
    loyverseWebhook([loyverseReceipt()]);
    $refund = loyverseReceipt(['receipt_number' => '2-1010', 'receipt_type' => 'REFUND', 'refund_for' => '2-1008', 'payments' => [['payment_type_id' => 'pt-room', 'money_amount' => 85.5]]]);

    loyverseWebhook([$refund]);
    loyverseWebhook([$refund]);
    $charge = PosPosting::query()->where('posting_type', PosPostingType::Charge)->sole();
    $void = PosPosting::query()->where('posting_type', PosPostingType::Void)->sole();

    expect($charge->status)->toBe(PosPostingStatus::Reversed)
        ->and($void->status)->toBe(PosPostingStatus::Reversed)
        ->and($void->external_receipt_id)->toBe('refund:2-1010')
        ->and(app(FolioBalance::class)->forFolio(FolioLine::query()->findOrFail($charge->folio_line_id)->folio))->toBe('0.00');
});

it('keeps a partial refund for front desk and leaves the charge posted', function () {
    $setup = loyverseSetup();
    loyverseLink($setup);
    loyverseWebhook([loyverseReceipt()]);

    loyverseWebhook([loyverseReceipt(['receipt_number' => '2-1011', 'receipt_type' => 'REFUND', 'refund_for' => '2-1008', 'payments' => [['payment_type_id' => 'pt-room', 'money_amount' => 20.0]]])]);
    $void = PosPosting::query()->where('posting_type', PosPostingType::Void)->sole();

    expect($void->status)->toBe(PosPostingStatus::PendingReview)
        ->and($void->failure_reason)->toBe('partial_refund_manual_review')
        ->and(PosPosting::query()->where('posting_type', PosPostingType::Charge)->sole()->status)->toBe(PosPostingStatus::Posted);
});

it('voids a sale that Loyverse reports as cancelled, once', function () {
    $setup = loyverseSetup();
    loyverseLink($setup);
    loyverseWebhook([loyverseReceipt()]);
    $cancelled = loyverseReceipt(['cancelled_at' => '2026-10-14T05:00:00.000Z']);

    loyverseWebhook([$cancelled]);
    loyverseWebhook([$cancelled]);

    expect(PosPosting::query()->where('posting_type', PosPostingType::Charge)->sole()->status)->toBe(PosPostingStatus::Reversed)
        ->and(PosPosting::query()->where('posting_type', PosPostingType::Void)->sole()->external_receipt_id)->toBe('cancel:2-1008');
});

it('ignores a refund that names no original receipt', function () {
    $setup = loyverseSetup();
    loyverseLink($setup);

    loyverseWebhook([loyverseReceipt(['receipt_type' => 'REFUND', 'refund_for' => null])]);

    expect(PosPosting::query()->count())->toBe(0);
});
