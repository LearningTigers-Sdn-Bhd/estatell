<?php

use App\Services\Notifications\MillionSendTransport;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mime\Email;

function millionSendEmail(): Email
{
    return (new Email)
        ->from('Estatell <noreply@estatell.test>')
        ->to('guest@example.test')
        ->cc('cc@example.test')
        ->replyTo('front@example.test')
        ->subject('Hello')
        ->html('<p>Hi</p>')
        ->text('Hi')
        ->attach('pdf-body', 'folio.pdf', 'application/pdf');
}

function millionSendTransport(): MillionSendTransport
{
    return new MillionSendTransport('https://mail.example.test/', 'ms_key');
}

it('posts the message to the emails endpoint with bearer auth', function () {
    Http::fake(['mail.example.test/*' => Http::response(['id' => 'abc'], 200)]);

    millionSendTransport()->send(millionSendEmail());

    Http::assertSent(function (Request $request) {
        return $request->url() === 'https://mail.example.test/emails'
            && $request->hasHeader('Authorization', 'Bearer ms_key')
            && $request['from'] === '"Estatell" <noreply@estatell.test>'
            && $request['to'] === ['guest@example.test']
            && $request['cc'] === ['cc@example.test']
            && $request['reply_to'] === ['front@example.test']
            && $request['subject'] === 'Hello'
            && $request['html'] === '<p>Hi</p>'
            && $request['text'] === 'Hi'
            && $request['attachments'][0]['filename'] === 'folio.pdf'
            && $request['attachments'][0]['content'] === base64_encode('pdf-body')
            && $request['attachments'][0]['content_type'] === 'application/pdf';
    });
});

it('skips and logs without addresses when every recipient is suppressed', function () {
    Http::fake(['*' => Http::response(['name' => 'all_recipients_suppressed'], 422)]);
    Log::spy();

    millionSendTransport()->send(millionSendEmail());

    Log::shouldHaveReceived('warning')->once()->withArgs(
        fn (string $message, array $context) => $context['recipients'] === 1
            && ! str_contains(json_encode($context), 'guest@example.test'),
    );
});

it('throws on other API errors so the queued job retries', function () {
    Http::fake(['*' => Http::response(['name' => 'validation_error'], 422)]);

    millionSendTransport()->send(millionSendEmail());
})->throws(TransportException::class, 'MillionSend 422');

it('builds the millionsend mailer from the services config', function () {
    config([
        'mail.mailers.millionsend' => ['transport' => 'millionsend'],
        'services.millionsend.base_url' => 'https://mail.example.test',
        'services.millionsend.api_key' => 'ms_key',
    ]);

    expect((string) Mail::mailer('millionsend')->getSymfonyTransport())->toBe('millionsend');
});

it('refuses to build the mailer when the base url or key is missing', function () {
    config(['services.millionsend.base_url' => null, 'services.millionsend.api_key' => 'ms_key']);

    Mail::mailer('millionsend');
})->throws(InvalidArgumentException::class, 'MILLIONSEND_BASE_URL');
