<?php

namespace App\Actions\Webhooks;

use App\Enums\WebhookEvent;
use App\Jobs\BroadcastWebhookJob;
use App\Models\WebhookEndpoint;
use Closure;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Context;

/**
 * The one line an Action calls when something a webhook can announce happens. It queues `BroadcastWebhookJob` after
 * the transaction commits, so a rolled-back change sends nothing and the payload shows the saved state.
 *
 * - Nothing is queued when no active endpoint hears the event at the property.
 * - One event per subject per request or job: cancelling three units of one booking sends one `booking.cancelled`.
 * - Imports and back-dated stays run inside `muted()` and send nothing.
 *
 * See docs/32-webhooks/01-outgoing-webhooks.md#events.
 */
#[Scoped]
class TriggerWebhook
{
    public const string MUTED = 'webhooks.muted';

    /**
     * @var array<string, true>
     */
    private array $queued = [];

    /**
     * `$subjectType` and `$subjectId` name the model the payload is built from, for example the reservation of a
     * booking event, so the caller never loads a relation only to send a webhook.
     *
     * @param  class-string<Model>  $subjectType
     * @param  array<string, mixed>  $context
     */
    public function __invoke(WebhookEvent $event, int $propertyId, string $subjectType, int $subjectId, array $context = []): void
    {
        if (Context::getHidden(self::MUTED) === true) {
            return;
        }

        $key = $event->value.':'.$subjectType.':'.$subjectId;

        if (isset($this->queued[$key])) {
            return;
        }

        if (! WebhookEndpoint::query()->listeningFor($event, $propertyId)->exists()) {
            return;
        }

        $this->queued[$key] = true;

        BroadcastWebhookJob::dispatch($event, $propertyId, $subjectType, $subjectId, $context, now())->afterCommit();
    }

    /**
     * Run the callback with webhooks turned off, also for the jobs it queues.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    public static function muted(Closure $callback): mixed
    {
        return Context::scope($callback, hidden: [self::MUTED => true]);
    }
}
