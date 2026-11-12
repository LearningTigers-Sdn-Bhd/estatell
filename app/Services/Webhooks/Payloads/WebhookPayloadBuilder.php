<?php

namespace App\Services\Webhooks\Payloads;

use App\Enums\WebhookEvent;
use Illuminate\Database\Eloquent\Model;

/**
 * Builds the `data` of one event group. Returns null when the subject no longer allows a payload, so nothing is sent.
 */
interface WebhookPayloadBuilder
{
    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>|null
     */
    public function build(WebhookEvent $event, Model $subject, array $context): ?array;
}
