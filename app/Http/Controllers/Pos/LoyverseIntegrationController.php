<?php

namespace App\Http\Controllers\Pos;

use App\Actions\Pos\DisconnectLoyverse;
use App\Actions\Pos\SaveLoyverseMappings;
use App\Data\Shared\BadgeData;
use App\Enums\PosConnectionStatus;
use App\Enums\PosProvider;
use App\Enums\Tone;
use App\Enums\UnitStatus;
use App\Features\PosIntegration;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\SaveLoyverseMappingsRequest;
use App\Jobs\SyncLoyverseGuestJob;
use App\Models\PosConnection;
use App\Models\PosGuestLink;
use App\Models\PosOutlet;
use App\Models\PosOutletMapping;
use App\Models\Property;
use App\Models\ReservationUnit;
use App\Services\Pos\Loyverse\LoyverseClient;
use App\Services\Pos\Loyverse\LoyverseException;
use App\Services\Pos\PosRefusalMessages;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Pennant\Feature;

class LoyverseIntegrationController extends Controller
{
    public function __construct(private LoyverseClient $client) {}

    /**
     * Connection status, store and payment type mapping, and sync controls. With POS integration off it shows the
     * "contact support" page instead of a 404, so a merchant who arrives from the Loyverse App Marketplace is not lost.
     */
    public function index(Property $property): Response
    {
        if (! Feature::for($property)->active(PosIntegration::class)) {
            return Inertia::render('settings/pos-integrations/activate');
        }

        $connection = $this->connection($property);

        return Inertia::render('settings/pos-integrations/index', [
            'configured' => $this->client->isConfigured(),
            'connection' => $connection === null ? null : [
                'status' => new BadgeData($connection->status->value, $connection->status->label(), Tone::from($connection->status->tone())),
                'merchantId' => $connection->external_merchant_id,
                'lastSyncedAt' => $connection->last_synced_at?->toIso8601String(),
                'lastError' => $connection->last_error,
                'connected' => $connection->status === PosConnectionStatus::Connected,
            ],
            'outlets' => PosOutlet::query()->where('property_id', $property->id)->active()->orderBy('name')->get(['id', 'name'])->map(fn (PosOutlet $outlet): array => ['value' => (string) $outlet->id, 'label' => $outlet->name])->values(),
            'catalogue' => $connection === null ? null : Cache::get(self::catalogueKey($connection)),
            'mappings' => $connection === null ? [] : PosOutletMapping::query()->where('pos_connection_id', $connection->id)->get()->map(fn (PosOutletMapping $mapping): array => [
                'storeId' => $mapping->external_store_id,
                'outletId' => (string) $mapping->pos_outlet_id,
                'paymentTypeId' => $mapping->external_payment_type_id,
                'active' => $mapping->active,
            ])->values(),
            'guestCount' => $connection === null ? 0 : PosGuestLink::query()->where('pos_connection_id', $connection->id)->count(),
        ]);
    }

    /**
     * Send the merchant to Loyverse to allow access. The `state` is single use, lives 10 minutes and names the property
     * and the staff member, so the callback can tell who started it.
     */
    public function connect(Request $request, Property $property): RedirectResponse
    {
        if (! $this->client->isConfigured()) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Loyverse is not set up on this server yet. Ask support.')]);

            return to_route('settings.pos-integrations.index', $property);
        }

        $state = Str::random(40);
        Cache::put('loyverse-oauth:'.$state, ['property_id' => $property->id, 'user_id' => $request->user()->id], now()->addMinutes(10));

        return redirect()->away($this->client->authorizeUrl($state));
    }

    public function refreshCatalog(Property $property): RedirectResponse
    {
        $connection = $this->connected($property);

        try {
            Cache::put(self::catalogueKey($connection), ['stores' => $this->client->stores($connection), 'paymentTypes' => $this->client->paymentTypes($connection)], now()->addDay());
            $connection->forceFill(['last_synced_at' => now()])->save();
            Inertia::flash('toast', ['type' => 'success', 'message' => __('Loyverse stores and payment types refreshed.')]);
        } catch (LoyverseException $exception) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $exception->getMessage()]);
        }

        return to_route('settings.pos-integrations.index', $property);
    }

    public function updateMappings(SaveLoyverseMappingsRequest $request, Property $property, SaveLoyverseMappings $saveLoyverseMappings): RedirectResponse
    {
        $refusal = $saveLoyverseMappings($request->user(), $property, $this->connected($property), array_values(array_map(fn (array $row): array => [
            'store_id' => (string) $row['store_id'],
            'pos_outlet_id' => ($row['pos_outlet_id'] ?? null) === null ? null : (int) $row['pos_outlet_id'],
            'payment_type_id' => $row['payment_type_id'] ?? null,
            'active' => (bool) $row['active'],
        ], $request->validated('mappings'))));

        Inertia::flash('toast', $refusal === null
            ? ['type' => 'success', 'message' => __('Mappings saved.')]
            : ['type' => 'error', 'message' => PosRefusalMessages::message($refusal)]);

        return to_route('settings.pos-integrations.index', $property);
    }

    /**
     * Backfill the guests already in house, right after the first connect.
     */
    public function syncGuests(Property $property): RedirectResponse
    {
        $connection = $this->connected($property);
        $ids = ReservationUnit::query()->where('property_id', $property->id)->where('status', UnitStatus::CheckedIn)->pluck('id');

        foreach ($ids as $id) {
            SyncLoyverseGuestJob::dispatch($connection->id, (int) $id);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice('Syncing :count in-house guest to Loyverse.|Syncing :count in-house guests to Loyverse.', $ids->count())]);

        return to_route('settings.pos-integrations.index', $property);
    }

    public function disconnect(Request $request, Property $property, DisconnectLoyverse $disconnectLoyverse): RedirectResponse
    {
        $connection = $this->connection($property);

        Inertia::flash('toast', $connection !== null && $disconnectLoyverse($request->user(), $connection)
            ? ['type' => 'success', 'message' => __('Loyverse disconnected.')]
            : ['type' => 'error', 'message' => __('Loyverse is already disconnected.')]);

        return to_route('settings.pos-integrations.index', $property);
    }

    private function connection(Property $property): ?PosConnection
    {
        return PosConnection::query()->where('property_id', $property->id)->where('provider', PosProvider::Loyverse)->first();
    }

    private function connected(Property $property): PosConnection
    {
        return PosConnection::query()
            ->where('property_id', $property->id)
            ->where('provider', PosProvider::Loyverse)
            ->where('status', PosConnectionStatus::Connected)
            ->firstOrFail();
    }

    private static function catalogueKey(PosConnection $connection): string
    {
        return 'loyverse-catalogue:'.$connection->id;
    }
}
