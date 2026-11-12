<?php

namespace App\Services\Webhooks\Payloads;

use App\Enums\WebhookEvent;
use App\Models\Guest;
use Illuminate\Database\Eloquent\Model;

/**
 * Guest contact details only: name, email, phone and country. Identity documents, tax details, notes and the
 * blacklist are never sent. An anonymized guest sends only its id. See docs/32-webhooks/01-outgoing-webhooks.md.
 */
class GuestWebhookPayload implements WebhookPayloadBuilder
{
    public function build(WebhookEvent $event, Model $subject, array $context): ?array
    {
        if (! $subject instanceof Guest) {
            return null;
        }

        return match ($event) {
            WebhookEvent::GuestAnonymized => ['guest' => ['id' => $subject->id]],
            WebhookEvent::GuestMerged => ['guest' => $this->contact($subject), 'removed_guest_id' => $context['removed_guest_id'] ?? null],
            default => $subject->anonymized_at === null ? ['guest' => $this->contact($subject)] : null,
        };
    }

    /**
     * @return array{id: int, name: string, email: string|null, phone: string|null, country_code: string|null}
     */
    public function contact(Guest $guest): array
    {
        return [
            'id' => $guest->id,
            'name' => $guest->full_name,
            'email' => $guest->email,
            'phone' => $guest->phone,
            'country_code' => $guest->country_code ?? $guest->nationality_code,
        ];
    }
}
