<?php

namespace App\Services\Notifications;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Message;
use Symfony\Component\Mime\MessageConverter;

/**
 * Mail transport for MillionSend, a Resend-compatible email API. See docs/04-notifications/01-notification-types.md.
 */
class MillionSendTransport extends AbstractTransport
{
    public function __construct(protected string $baseUrl, protected string $apiKey)
    {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        $original = $message->getOriginalMessage();

        if (! $original instanceof Message) {
            throw new TransportException('MillionSend cannot send a raw message.');
        }

        $email = MessageConverter::toEmail($original);

        $response = Http::withToken($this->apiKey)
            ->acceptJson()
            ->timeout(30)
            ->post(rtrim($this->baseUrl, '/').'/emails', $this->payload($email));

        if ($response->successful()) {
            return;
        }

        if ($response->status() === 422 && $response->json('name') === 'all_recipients_suppressed') {
            // No address or subject: both can be personal data.
            Log::warning('MillionSend skipped delivery, all recipients suppressed', [
                'recipients' => count($email->getTo()),
                'message_id' => $email->getHeaders()->get('Message-ID')?->getBodyAsString(),
            ]);

            return;
        }

        throw new TransportException('MillionSend '.$response->status().': '.str($response->body())->limit(300), $response->status());
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(Email $email): array
    {
        return array_filter([
            'from' => $email->getFrom()[0]->toString(),
            'to' => $this->addresses($email->getTo()),
            'cc' => $this->addresses($email->getCc()),
            'bcc' => $this->addresses($email->getBcc()),
            'reply_to' => $this->addresses($email->getReplyTo()),
            'subject' => $email->getSubject(),
            'html' => $email->getHtmlBody(),
            'text' => $email->getTextBody(),
            'attachments' => $this->attachments($email),
        ]);
    }

    /**
     * @param  array<int, Address>  $addresses
     * @return array<int, string>
     */
    protected function addresses(array $addresses): array
    {
        return array_map(fn (Address $address): string => $address->toString(), $addresses);
    }

    /**
     * @return array<int, array<string, string>>
     */
    protected function attachments(Email $email): array
    {
        return array_map(fn ($part): array => array_filter([
            'filename' => $part->getFilename(),
            'content' => base64_encode($part->getBody()),
            'content_type' => $part->getMediaType().'/'.$part->getMediaSubtype(),
            'content_id' => $part->getDisposition() === 'inline' ? $part->getContentId() : null,
        ]), $email->getAttachments());
    }

    public function __toString(): string
    {
        return 'millionsend';
    }
}
