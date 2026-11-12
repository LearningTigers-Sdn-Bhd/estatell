<?php

namespace App\Jobs;

use App\Actions\Webhooks\BroadcastWebhook;
use App\Enums\WebhookEvent;
use App\Models\Property;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Builds the payload of one event and hands it to every endpoint that hears it. It runs after the change committed,
 * so the payload shows the saved state. A subject that is gone sends nothing.
 */
class BroadcastWebhookJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @param  class-string<Model>  $subjectType
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly WebhookEvent $event,
        public readonly int $propertyId,
        public readonly string $subjectType,
        public readonly int $subjectId,
        public readonly array $context,
        public readonly CarbonImmutable $occurredAt,
    ) {}

    public function handle(BroadcastWebhook $broadcast): void
    {
        $property = Property::query()->find($this->propertyId);
        $subject = $this->subjectType::query()->find($this->subjectId);

        if ($property === null || ! $subject instanceof Model) {
            return;
        }

        $data = app($this->event->payloadBuilder())->build($this->event, $subject, $this->context);

        if ($data !== null) {
            $broadcast($this->event, $property, $data, $this->occurredAt);
        }
    }
}
