<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Webhooks\DeleteWebhookEndpoint;
use App\Actions\Webhooks\RotateWebhookSecret;
use App\Actions\Webhooks\SaveWebhookEndpoint;
use App\Actions\Webhooks\SendTestWebhook;
use App\Actions\Webhooks\SetWebhookEndpointActive;
use App\Data\Webhooks\WebhookDeliveryData;
use App\Data\Webhooks\WebhookDeliveryRowData;
use App\Data\Webhooks\WebhookEndpointData;
use App\Data\Webhooks\WebhookEndpointInputData;
use App\Enums\WebhookDeliveryStatus;
use App\Enums\WebhookEvent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SendTestWebhookRequest;
use App\Http\Requests\Admin\SetWebhookEndpointActiveRequest;
use App\Http\Requests\Admin\WebhookEndpointRequest;
use App\Models\Property;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\Webhooks\WebhookDeliveryDirectory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\LaravelData\PaginatedDataCollection;

/**
 * The admin Webhooks pages. See docs/32-webhooks/01-outgoing-webhooks.md#admin-page.
 */
class WebhookEndpointController extends Controller
{
    /**
     * Every endpoint. The list is bounded, so it loads once and filters in the browser.
     */
    public function index(): Response
    {
        return Inertia::render('admin/webhooks/index', [
            'endpoints' => WebhookEndpoint::query()->with(WebhookEndpointData::RELATIONS)->orderBy('name')->get()
                ->map(fn (WebhookEndpoint $endpoint): WebhookEndpointData => WebhookEndpointData::fromModel($endpoint)),
            ...$this->formOptions(),
        ]);
    }

    /**
     * Every delivery across all endpoints, newest first.
     */
    public function deliveries(Request $request, WebhookDeliveryDirectory $directory): Response
    {
        return Inertia::render('admin/webhooks/deliveries', [
            'deliveries' => WebhookDeliveryRowData::collect($directory->paginate($request), PaginatedDataCollection::class),
            'filters' => $this->deliveryFilters($request, withEndpoint: true),
            'endpointOptions' => WebhookEndpoint::query()->orderBy('name')->get(['id', 'name'])
                ->map(fn (WebhookEndpoint $endpoint): array => ['value' => (string) $endpoint->id, 'label' => $endpoint->name])->values(),
            'events' => WebhookEvent::options(),
            'statuses' => WebhookDeliveryStatus::options(),
            'selectedDelivery' => $this->selectedDelivery($request),
        ]);
    }

    public function show(Request $request, WebhookEndpoint $webhookEndpoint, WebhookDeliveryDirectory $directory): Response
    {
        return Inertia::render('admin/webhooks/show', [
            'endpoint' => WebhookEndpointData::fromModel($webhookEndpoint->load(WebhookEndpointData::RELATIONS)),
            'stats' => $directory->stats($webhookEndpoint),
            'deliveries' => WebhookDeliveryRowData::collect($directory->paginate($request, $webhookEndpoint), PaginatedDataCollection::class),
            'filters' => $this->deliveryFilters($request, withEndpoint: false),
            'statuses' => WebhookDeliveryStatus::options(),
            'selectedDelivery' => $this->selectedDelivery($request, $webhookEndpoint),
            ...$this->formOptions(),
        ]);
    }

    public function store(WebhookEndpointRequest $request, SaveWebhookEndpoint $save): RedirectResponse
    {
        ['endpoint' => $endpoint, 'secret' => $secret] = $save($request->user(), WebhookEndpointInputData::fromRequest($request));

        Inertia::flash('webhookSecret', ['endpointName' => $endpoint->name, 'secret' => $secret]);

        return to_route('admin.webhooks.show', $endpoint);
    }

    public function update(WebhookEndpointRequest $request, WebhookEndpoint $webhookEndpoint, SaveWebhookEndpoint $save): RedirectResponse
    {
        $save($request->user(), WebhookEndpointInputData::fromRequest($request), $webhookEndpoint);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Endpoint saved.')]);

        return back();
    }

    public function active(SetWebhookEndpointActiveRequest $request, WebhookEndpoint $webhookEndpoint, SetWebhookEndpointActive $setActive): RedirectResponse
    {
        $active = $request->boolean('active');

        if (! $setActive($request->user(), $webhookEndpoint, $active)) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('That change did not apply. Reload the page and try again.')]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $active
            ? __(':name turned on.', ['name' => $webhookEndpoint->name])
            : __(':name turned off.', ['name' => $webhookEndpoint->name])]);

        return back();
    }

    public function rotateSecret(Request $request, WebhookEndpoint $webhookEndpoint, RotateWebhookSecret $rotate): RedirectResponse
    {
        Inertia::flash('webhookSecret', ['endpointName' => $webhookEndpoint->name, 'secret' => $rotate($request->user(), $webhookEndpoint)]);

        return to_route('admin.webhooks.show', $webhookEndpoint);
    }

    public function destroy(Request $request, WebhookEndpoint $webhookEndpoint, DeleteWebhookEndpoint $delete): RedirectResponse
    {
        $name = $webhookEndpoint->name;
        $delete($request->user(), $webhookEndpoint);

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name deleted.', ['name' => $name])]);

        return to_route('admin.webhooks.index');
    }

    public function test(SendTestWebhookRequest $request, WebhookEndpoint $webhookEndpoint, SendTestWebhook $send): RedirectResponse
    {
        $event = WebhookEvent::from($request->string('event')->toString());

        if ($send($webhookEndpoint, $event) === null) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('This endpoint does not hear that event.')]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Test :event sent. It shows in the deliveries below.', ['event' => $event->value])]);

        return to_route('admin.webhooks.show', $webhookEndpoint);
    }

    /**
     * @return array{properties: list<array{value: string, label: string}>, events: list<array{value: string, label: string, group: string}>}
     */
    private function formOptions(): array
    {
        return [
            'properties' => array_map(
                fn (Property $property): array => ['value' => (string) $property->id, 'label' => $property->name],
                array_values(Property::query()->orderBy('name')->get(['id', 'name'])->all()),
            ),
            'events' => WebhookEvent::options(),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function deliveryFilters(Request $request, bool $withEndpoint): array
    {
        return array_filter([
            'endpoint' => $withEndpoint ? $request->string('filter.endpoint')->toString() : null,
            'event' => $request->string('filter.event')->toString(),
            'status' => $request->string('filter.status')->toString(),
        ], fn (?string $value): bool => $value !== null);
    }

    /**
     * The delivery whose Sheet is open, from `?delivery=`.
     */
    private function selectedDelivery(Request $request, ?WebhookEndpoint $endpoint = null): ?WebhookDeliveryData
    {
        $deliveryId = $request->integer('delivery');

        if ($deliveryId === 0) {
            return null;
        }

        $delivery = WebhookDelivery::query()
            ->with(WebhookDeliveryData::RELATIONS)
            ->when($endpoint !== null, fn ($query) => $query->where('webhook_endpoint_id', $endpoint?->id))
            ->find($deliveryId);

        return $delivery === null ? null : WebhookDeliveryData::fromModel($delivery);
    }
}
