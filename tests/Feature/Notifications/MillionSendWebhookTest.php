<?php

use App\Enums\MailStatus;
use App\Models\MailEvent;
use Illuminate\Testing\TestResponse;

const MILLIONSEND_WEBHOOK_KEY = 'a-test-signing-key-of-some-length';

/**
 * Post a signed MillionSend webhook. `$signature` and `$timestamp` can be overridden to test bad requests.
 *
 * @param  array<string, mixed>  $payload
 */
function postMillionSendWebhook(array $payload, ?string $signature = null, ?int $timestamp = null, string $id = 'msg_1'): TestResponse
{
    config(['services.millionsend.webhook_secret' => 'whsec_'.base64_encode(MILLIONSEND_WEBHOOK_KEY)]);

    $body = json_encode($payload);
    $timestamp ??= now()->getTimestamp();
    $signature ??= 'v1,'.base64_encode(hash_hmac('sha256', "{$id}.{$timestamp}.{$body}", MILLIONSEND_WEBHOOK_KEY, true));

    return test()->call('POST', route('webhooks.millionsend'), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_WEBHOOK_ID' => $id,
        'HTTP_WEBHOOK_TIMESTAMP' => (string) $timestamp,
        'HTTP_WEBHOOK_SIGNATURE' => $signature,
    ], $body);
}

function millionSendEvent(string $type, string $emailId = 'prov-1'): array
{
    return ['type' => $type, 'created_at' => now()->toIso8601String(), 'data' => ['email_id' => $emailId]];
}

it('moves a sent row to delivered with a valid signature', function () {
    $event = MailEvent::factory()->create(['provider_message_id' => 'prov-1']);

    postMillionSendWebhook(millionSendEvent('email.delivered'))->assertOk();

    expect($event->refresh()->status)->toBe(MailStatus::Delivered);
});

it('records a bounce with its reason', function () {
    $event = MailEvent::factory()->create(['provider_message_id' => 'prov-1']);

    postMillionSendWebhook(['type' => 'email.bounced', 'data' => ['email_id' => 'prov-1', 'bounce' => ['message' => 'Mailbox full']]])->assertOk();

    expect($event->refresh())->status->toBe(MailStatus::Bounced)->error->toBe('Mailbox full');
});

it('lets a delivered row become bounced or complained later', function (string $type, MailStatus $status) {
    $event = MailEvent::factory()->create(['provider_message_id' => 'prov-1', 'status' => MailStatus::Delivered]);

    postMillionSendWebhook(millionSendEvent($type))->assertOk();

    expect($event->refresh()->status)->toBe($status);
})->with([['email.bounced', MailStatus::Bounced], ['email.complained', MailStatus::Complained]]);

it('ignores repeated, late, unknown and unused events but still answers 200', function () {
    $bounced = MailEvent::factory()->create(['provider_message_id' => 'prov-1', 'status' => MailStatus::Bounced]);
    $sent = MailEvent::factory()->create(['provider_message_id' => 'prov-2']);

    postMillionSendWebhook(millionSendEvent('email.delivered'))->assertOk();
    postMillionSendWebhook(millionSendEvent('email.bounced'))->assertOk();
    postMillionSendWebhook(millionSendEvent('email.delivered', 'unknown-id'))->assertOk();
    postMillionSendWebhook(millionSendEvent('email.opened', 'prov-2'))->assertOk();
    postMillionSendWebhook(['type' => 'contact.created', 'data' => []])->assertOk();

    expect($bounced->refresh()->status)->toBe(MailStatus::Bounced)
        ->and($sent->refresh()->status)->toBe(MailStatus::Sent);
});

it('refuses a bad signature, a missing signature and a replay', function () {
    $event = MailEvent::factory()->create(['provider_message_id' => 'prov-1']);
    $payload = millionSendEvent('email.delivered');

    postMillionSendWebhook($payload, signature: 'v1,'.base64_encode('wrong'))->assertUnauthorized();
    postMillionSendWebhook($payload, signature: '')->assertUnauthorized();
    postMillionSendWebhook($payload, timestamp: now()->subMinutes(6)->getTimestamp())->assertUnauthorized();

    expect($event->refresh()->status)->toBe(MailStatus::Sent);
});

it('refuses every webhook when no secret is set', function () {
    $this->postJson(route('webhooks.millionsend'), millionSendEvent('email.delivered'))->assertUnauthorized();

    config(['services.millionsend.webhook_secret' => null]);

    $this->postJson(route('webhooks.millionsend'), millionSendEvent('email.delivered'))->assertUnauthorized();
});

it('accepts any matching signature while the key rotates', function () {
    $event = MailEvent::factory()->create(['provider_message_id' => 'prov-1']);
    $payload = millionSendEvent('email.delivered');
    $timestamp = now()->getTimestamp();
    $good = base64_encode(hash_hmac('sha256', "msg_1.{$timestamp}.".json_encode($payload), MILLIONSEND_WEBHOOK_KEY, true));

    postMillionSendWebhook($payload, signature: 'v1,'.base64_encode('old').' v1,'.$good, timestamp: $timestamp)->assertOk();

    expect($event->refresh()->status)->toBe(MailStatus::Delivered);
});
