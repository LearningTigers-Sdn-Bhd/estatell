<?php

namespace App\Services\Webhooks;

use App\Data\Webhooks\WebhookDeliveryRowData;
use App\Enums\WebhookDeliveryStatus;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * Webhook deliveries for the admin pages, newest first, with the endpoint, event and status filters in the URL.
 */
class WebhookDeliveryDirectory
{
    public const int PER_PAGE = 25;

    /**
     * @return LengthAwarePaginator<int, WebhookDelivery>
     */
    public function paginate(Request $request, ?WebhookEndpoint $endpoint = null): LengthAwarePaginator
    {
        $deliveries = WebhookDelivery::query()->when($endpoint !== null, fn ($query) => $query->where('webhook_endpoint_id', $endpoint?->id));

        return QueryBuilder::for($deliveries, $request)
            ->allowedFilters(
                AllowedFilter::exact('endpoint', 'webhook_endpoint_id'),
                AllowedFilter::exact('event'),
                AllowedFilter::exact('status'),
            )
            ->defaultSort('-id')
            ->with(WebhookDeliveryRowData::RELATIONS)
            ->paginate(self::PER_PAGE)
            ->withQueryString();
    }

    /**
     * Deliveries in the last 24 hours, failed ones in the last 24 hours, and the last successful delivery.
     *
     * @return array{sent: int, failed: int, lastDeliveredAt: string|null}
     */
    public function stats(WebhookEndpoint $endpoint): array
    {
        $recent = WebhookDelivery::query()->where('webhook_endpoint_id', $endpoint->id)->where('created_at', '>=', now()->subDay());

        return [
            'sent' => (clone $recent)->count(),
            'failed' => (clone $recent)->whereIn('status', [WebhookDeliveryStatus::Refused, WebhookDeliveryStatus::Failed])->count(),
            'lastDeliveredAt' => WebhookDelivery::query()->where('webhook_endpoint_id', $endpoint->id)->whereNotNull('delivered_at')->latest('delivered_at')->first(['delivered_at'])?->delivered_at?->format('Y-m-d H:i'),
        ];
    }
}
